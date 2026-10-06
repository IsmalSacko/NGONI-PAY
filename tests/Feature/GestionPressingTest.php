<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Client;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Pressing, offre Pro : dépenses (sortent de la caisse), fournitures, forfaits, relevé mensuel. */
class GestionPressingTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    private string $client;

    private string $chemise;

    private string $lavage;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Awa', 'pays' => 'ML', 'telephone' => '76008201', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        Produit::query()->delete();
        $this->client = Client::create(['nom' => 'Hôtel Kanaga', 'telephone' => '+22370112233'])->id;
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'pressing'])->assertOk();
        $this->lavage = collect($this->api()->getJson('/api/services')->json('data'))->firstWhere('nom', 'Lavage + repassage')['id'];
        $this->chemise = $this->api()->postJson('/api/produits', [
            'nom' => 'Chemise', 'prix_vente' => 600, 'taux_tva' => 0, 'tarifs' => [['service_id' => $this->lavage, 'prix' => 600]],
        ])->assertCreated()->json('id');
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($this->awa->createToken('t')->plainTextToken);
    }

    private function deposer(int $pieces, array $extra = [])
    {
        return $this->api()->postJson('/api/commandes-pressing', [
            'client_id' => $this->client,
            'lignes' => [['produit_id' => $this->chemise, 'service_id' => $this->lavage, 'quantite' => $pieces]],
            ...$extra,
        ]);
    }

    public function test_une_depense_en_especes_sort_de_la_caisse_du_jour(): void
    {
        $this->api()->postJson('/api/sessions-caisse', ['fond_initial' => 10000])->assertCreated();
        $this->api()->postJson('/api/pressing/depenses', ['libelle' => 'Électricité', 'categorie' => 'energie', 'montant' => 3000])->assertCreated();
        $this->api()->postJson('/api/pressing/depenses', ['libelle' => 'Taxi', 'categorie' => 'transport', 'montant' => 500, 'moyen_paiement' => 'orange_money'])->assertCreated();

        $this->api()->getJson('/api/sessions-caisse/courante')->assertJsonPath('fond_attendu', 7000);
        $this->api()->getJson('/api/pressing/depenses')->assertOk()->assertJsonPath('total', 3500)->assertJsonCount(2, 'data');
        $jour = now()->toDateString();
        $this->api()->getJson("/api/rapports?du={$jour}&au={$jour}")->assertJsonPath('encaisse.depenses', 3500);
    }

    public function test_fournitures_achat_devient_depense_et_alerte_de_seuil(): void
    {
        $id = $this->api()->postJson('/api/pressing/fournitures', ['nom' => 'Lessive', 'unite' => 'kg', 'quantite' => 2, 'seuil' => 5])
            ->assertCreated()->assertJsonPath('data.a_racheter', true)->json('data.id');
        $this->api()->postJson("/api/pressing/fournitures/{$id}/mouvement", ['quantite' => 10, 'montant' => 7500])->assertOk()
            ->assertJsonPath('data.quantite', 12)->assertJsonPath('data.a_racheter', false);
        $this->api()->postJson("/api/pressing/fournitures/{$id}/mouvement", ['quantite' => -20])->assertUnprocessable();
        $this->api()->getJson('/api/pressing/depenses')->assertJsonPath('data.0.libelle', 'Achat : Lessive')->assertJsonPath('total', 7500);
    }

    public function test_forfait_vendu_puis_deduit_aux_depots_sans_vente_au_retrait(): void
    {
        $forfait = $this->api()->postJson('/api/pressing/forfaits', [
            'client_id' => $this->client, 'libelle' => 'Mensuel', 'pieces' => 10, 'prix' => 5000,
            'fin' => now()->addMonth()->toDateString(), 'moyen_paiement' => 'especes',
        ])->assertCreated()->assertJsonPath('data.restantes', 10)->json('data');
        $this->assertSame(5000, (int) Vente::sole()->total, 'le forfait est encaissé à la vente');

        $c = $this->deposer(4, ['forfait_id' => $forfait['id']])->assertCreated()->assertJsonPath('data.total', 0)->json('data');
        $this->api()->getJson("/api/pressing/forfaits?client_id={$this->client}")->assertJsonPath('data.0.restantes', 6);
        $this->deposer(7, ['forfait_id' => $forfait['id']])->assertUnprocessable()->assertJsonValidationErrors('forfait_id');

        $this->api()->postJson("/api/commandes-pressing/{$c['id']}/retrait", ['moyen_paiement' => 'especes'])->assertOk()->assertJsonPath('data.statut', 'retiree');
        $this->assertSame(1, Vente::count(), 'pas de nouvelle vente : déjà payé');

        // Annulée : les pièces reviennent.
        $autre = $this->deposer(2, ['forfait_id' => $forfait['id']])->json('data.id');
        $this->api()->postJson("/api/commandes-pressing/{$autre}/annuler")->assertOk();
        $this->api()->getJson("/api/pressing/forfaits?client_id={$this->client}")->assertJsonPath('data.0.restantes', 6);
    }

    public function test_releve_mensuel_d_un_compte_entreprise(): void
    {
        foreach ([3, 2] as $pieces) {
            $id = $this->deposer($pieces)->json('data.id');
            $this->api()->postJson("/api/commandes-pressing/{$id}/retrait", ['moyen_paiement' => 'especes', 'credit' => true])->assertOk();
        }
        $releve = $this->api()->getJson("/api/pressing/releve/{$this->client}")->assertOk()->json('data');
        $this->assertCount(2, $releve['commandes']);
        $this->assertSame(3000, $releve['total']);
        $this->assertSame(3000, $releve['reste_du_mois']);
        $this->assertSame(3000, $releve['solde_du']);
    }

    public function test_hors_pro_ou_hors_pressing_refuse(): void
    {
        $this->awa->abonnement()->update(['plan' => 'basic', 'fin' => now()->addMonth()->toDateString()]);
        $this->api()->getJson('/api/pressing/depenses')->assertForbidden();
        $this->api()->getJson('/api/pressing/fournitures')->assertForbidden();
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'commerce'])->assertOk();
        $this->api()->getJson('/api/pressing/forfaits')->assertUnprocessable();
    }
}
