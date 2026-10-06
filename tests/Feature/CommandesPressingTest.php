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

/**
 * Pressing : la commande naît au dépôt (numéro unique, prix figés, acompte),
 * devient prête, puis la vente naît au retrait. Les autres boutiques n'y ont pas accès.
 */
class CommandesPressingTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    /** @var array<string, string> */
    private array $services;

    private string $chemise;

    private string $client;

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
        $this->client = Client::create(['nom' => 'Moussa Diarra', 'telephone' => '+22370112233'])->id;

        $this->api()->putJson('/api/boutique/activite', ['activite' => 'pressing'])->assertOk();
        $this->services = collect($this->api()->getJson('/api/services')->json('data'))->pluck('id', 'nom')->all();
        $this->chemise = $this->api()->postJson('/api/produits', [
            'nom' => 'Chemise', 'prix_vente' => 400, 'taux_tva' => 0,
            'tarifs' => [
                ['service_id' => $this->services['Lavage + repassage'], 'prix' => 600, 'prix_express' => 1000],
                ['service_id' => $this->services['Repassage seul'], 'prix' => 400],
            ],
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

    private function deposer(array $extra = [])
    {
        return $this->api()->postJson('/api/commandes-pressing', [
            'client_id' => $this->client,
            'lignes' => [
                ['produit_id' => $this->chemise, 'service_id' => $this->services['Lavage + repassage'], 'quantite' => 3, 'defauts' => 'tache au col'],
                ['produit_id' => $this->chemise, 'service_id' => $this->services['Repassage seul'], 'quantite' => 2],
            ],
            ...$extra,
        ]);
    }

    public function test_le_depot_cree_une_commande_numerotee_aux_prix_du_jour_sans_vente(): void
    {
        $commande = $this->deposer(['acompte' => 1000, 'moyen_acompte' => 'especes'])->assertCreated()->json('data');

        $this->assertSame('C-0001', $commande['numero_lisible']);
        $this->assertSame(3 * 600 + 2 * 400, $commande['total']);
        $this->assertSame(1000, $commande['acompte']);
        $this->assertSame(1600, $commande['reste']);
        $this->assertSame('deposee', $commande['statut']);
        $this->assertSame('tache au col', $commande['lignes'][0]['defauts']);
        $this->assertNotNull($commande['retrait_prevu_le']);
        $this->assertSame(0, Vente::count(), 'pas de vente au dépôt');

        // Deux dépôts, deux numéros : jamais le même.
        $this->assertSame('C-0002', $this->deposer()->assertCreated()->json('data.numero_lisible'));
    }

    public function test_le_retrait_cree_la_vente_aux_prix_figes_acompte_compris(): void
    {
        $id = $this->deposer(['acompte' => 1000])->json('data.id');

        // Le tarif change après le dépôt : la commande garde ses prix.
        $this->api()->putJson("/api/produits/{$this->chemise}", [
            'tarifs' => [['service_id' => $this->services['Lavage + repassage'], 'prix' => 900], ['service_id' => $this->services['Repassage seul'], 'prix' => 500]],
        ])->assertOk();

        $this->api()->postJson("/api/commandes-pressing/{$id}/prete")->assertOk()->assertJsonPath('data.statut', 'prete');
        $retrait = $this->api()->postJson("/api/commandes-pressing/{$id}/retrait", ['moyen_paiement' => 'especes', 'montant_donne' => 2000])
            ->assertOk()->assertJsonPath('data.statut', 'retiree');

        $vente = Vente::with('lignes')->findOrFail($retrait->json('data.vente_id'));
        $this->assertSame(2600, $vente->total, 'prix du dépôt, pas les nouveaux');
        $this->assertSame($this->client, $vente->client_id);
        $this->assertSame(['Chemise · Lavage + repassage', 'Chemise · Repassage seul'], $vente->lignes->pluck('nom_produit')->all());

        // Clôturée : ni second retrait ni annulation.
        $this->api()->postJson("/api/commandes-pressing/{$id}/retrait", ['moyen_paiement' => 'especes'])->assertUnprocessable();
        $this->api()->postJson("/api/commandes-pressing/{$id}/annuler")->assertUnprocessable();
    }

    public function test_recherche_par_numero_nom_telephone_et_filtres(): void
    {
        $this->deposer();
        $id = $this->deposer()->json('data.id');
        $this->api()->postJson("/api/commandes-pressing/{$id}/prete")->assertOk();

        $this->api()->getJson('/api/commandes-pressing?q=C-0002')->assertJsonCount(1, 'data')->assertJsonPath('data.0.numero', 2);
        $this->api()->getJson('/api/commandes-pressing?q=Moussa')->assertJsonCount(2, 'data');
        $this->api()->getJson('/api/commandes-pressing?q=70112233')->assertJsonCount(2, 'data');
        $this->api()->getJson('/api/commandes-pressing?filtre=pretes')->assertJsonCount(1, 'data');
        $this->api()->getJson('/api/commandes-pressing?filtre=en_cours')->assertJsonCount(1, 'data');

        $this->travel(5)->days();
        $this->api()->getJson('/api/commandes-pressing?filtre=retard')->assertJsonCount(2, 'data')->assertJsonPath('data.0.en_retard', true);
    }

    public function test_annuler_un_depot(): void
    {
        $id = $this->deposer()->json('data.id');

        $this->api()->postJson("/api/commandes-pressing/{$id}/annuler", ['motif' => 'Client a changé d’avis'])
            ->assertOk()->assertJsonPath('data.statut', 'annulee');
        $this->assertSame(0, Vente::count());
    }

    public function test_une_boutique_qui_n_est_pas_un_pressing_n_y_a_pas_acces(): void
    {
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'commerce'])->assertOk();

        $this->api()->getJson('/api/commandes-pressing')->assertUnprocessable();
        $this->deposer()->assertUnprocessable();
    }

    public function test_hors_pro_le_depot_express_est_refuse(): void
    {
        $this->awa->abonnement()->update(['plan' => 'basic', 'fin' => now()->addMonth()->toDateString()]);

        $this->deposer(['express' => true])->assertForbidden();
        $this->deposer()->assertCreated();
    }

    public function test_alertes_aujourdhui_retard_et_linge_abandonne(): void
    {
        $this->deposer(['retrait_prevu_le' => now()->setTime(23, 0)->toIso8601String()])->assertCreated();
        $this->deposer(['retrait_prevu_le' => now()->subDays(2)->toIso8601String()])->assertCreated();
        $vieille = $this->deposer(['retrait_prevu_le' => now()->subDays(40)->toIso8601String()])->assertCreated()->json('data');
        $this->deposer()->assertCreated();
        $this->assertSame('Awa', $vieille['servi_par']);

        $this->api()->getJson('/api/commandes-pressing/compteurs')->assertOk()
            ->assertJson(['data' => ['aujourdhui' => 1, 'retard' => 2, 'abandon' => 1, 'pretes' => 0]]);
        $this->assertCount(1, $this->api()->getJson('/api/commandes-pressing?filtre=aujourdhui')->json('data'));
        $this->assertSame([$vieille['numero']], array_column($this->api()->getJson('/api/commandes-pressing?filtre=abandon')->json('data'), 'numero'));

        // Retirée, elle sort des alertes.
        $this->api()->postJson("/api/commandes-pressing/{$vieille['id']}/retrait", ['moyen_paiement' => 'especes'])->assertOk();
        $this->api()->getJson('/api/commandes-pressing/compteurs')->assertJson(['data' => ['abandon' => 0, 'retard' => 1]]);
    }
}
