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
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * « Au bout de N achats, X % de remise » : le compte d'achats par client, la
 * remise calculée par le serveur, et le compte qui repart après elle.
 */
class FideliteTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    private Produit $pagne;

    private Client $fatou;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Awa', 'pays' => 'ML', 'telephone' => '76008201', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        $this->pagne = Produit::create(['nom' => 'Pagne', 'prix_vente' => 10000, 'taux_tva' => 0, 'stock' => 100]);
        $this->fatou = Client::create(['nom' => 'Fatou', 'telephone' => '+22370000005']);
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($this->awa->createToken('t')->plainTextToken);
    }

    /** @param  array<string, mixed>  $en_plus */
    private function vendre(array $en_plus = []): TestResponse
    {
        return $this->api()->postJson('/api/ventes', [
            'client_id' => $this->fatou->id,
            'lignes' => [['produit_id' => $this->pagne->id, 'quantite' => 1]],
            'moyen_paiement' => 'especes',
            ...$en_plus,
        ]);
    }

    private function achatsFatou(): int
    {
        return (int) collect($this->api()->getJson('/api/clients')->assertOk()->json('data'))->firstWhere('id', $this->fatou->id)['fidelite_achats'];
    }

    public function test_le_programme_se_regle_et_se_retire(): void
    {
        $this->api()->putJson('/api/boutique/fidelite', ['seuil' => 5, 'remise_pct' => 10])->assertOk()
            ->assertJsonPath('data.fidelite_seuil', 5)->assertJsonPath('data.fidelite_remise_pct', 10);

        $this->api()->putJson('/api/boutique/fidelite', ['seuil' => 1, 'remise_pct' => 10])->assertUnprocessable();
        $this->api()->putJson('/api/boutique/fidelite', ['seuil' => 5, 'remise_pct' => 80])->assertUnprocessable();

        $this->api()->putJson('/api/boutique/fidelite', ['seuil' => null])->assertOk()
            ->assertJsonPath('data.fidelite_seuil', null)->assertJsonPath('data.fidelite_remise_pct', null);
    }

    public function test_les_achats_se_comptent_et_la_remise_les_remet_a_zero(): void
    {
        $this->api()->putJson('/api/boutique/fidelite', ['seuil' => 3, 'remise_pct' => 15])->assertOk();
        $this->vendre()->assertCreated();
        $this->vendre()->assertCreated();
        $this->vendre()->assertCreated();
        $this->assertSame(3, $this->achatsFatou());

        // Le serveur calcule la remise : 15 % de 10 000, quoi que l'app envoie.
        $vente = $this->vendre(['remise_fidelite' => true, 'remise' => 9999])->assertCreated();
        $vente->assertJsonPath('remise', 1500)->assertJsonPath('total', 8500)->assertJsonPath('remise_fidelite', true);

        $this->assertSame(0, $this->achatsFatou(), 'le compte repart de la remise');
        $this->travel(1)->seconds();
        $this->vendre()->assertCreated();
        $this->assertSame(1, $this->achatsFatou());
    }

    public function test_une_vente_annulee_ne_compte_pas(): void
    {
        $id = $this->vendre()->assertCreated()->json('id');
        $this->api()->postJson("/api/ventes/{$id}/annuler", ['motif' => 'Erreur'])->assertOk();

        $this->assertSame(0, $this->achatsFatou());
    }

    public function test_une_remise_de_fidelite_demande_un_client(): void
    {
        $this->api()->putJson('/api/boutique/fidelite', ['seuil' => 3, 'remise_pct' => 15])->assertOk();

        $this->api()->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $this->pagne->id, 'quantite' => 1]], 'moyen_paiement' => 'especes', 'remise_fidelite' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('client_id');
    }

    public function test_sans_programme_la_remise_saisie_reste_et_la_vente_passe(): void
    {
        // Vente hors ligne faite quand le programme existait, synchronisée après son retrait :
        // jamais refusée, la remise du ticket est gardée.
        $this->vendre(['remise_fidelite' => true, 'remise' => 1500, 'reference_locale' => (string) Str::uuid(), 'vendue_hors_ligne' => true])
            ->assertCreated()->assertJsonPath('remise', 1500)->assertJsonPath('remise_fidelite', false);
    }

    public function test_une_application_ancienne_n_est_pas_touchee(): void
    {
        $this->api()->putJson('/api/boutique/fidelite', ['seuil' => 3, 'remise_pct' => 15])->assertOk();

        $this->vendre(['remise' => 500])->assertCreated()->assertJsonPath('remise', 500)->assertJsonPath('remise_fidelite', false);
        $this->assertSame(1, Vente::withoutBoutiqueScope()->count());
    }
}
