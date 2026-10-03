<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Achats et fournisseurs, fidélité, droits par membre, factures et exports,
 * objectif du mois : inclus au Pro, absents du Basic (Plan::FONCTIONNALITES).
 */
class FonctionsDuPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Awa', 'pays' => 'ML', 'telephone' => '76008201', 'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($this->awa->createToken('t')->plainTextToken);
    }

    private function plan(string $code): void
    {
        app(AbonnementService::class)->accorder($this->awa, $code, now()->addMonth(), $this->awa);
    }

    public function test_le_basic_n_ouvre_pas_les_fonctions_du_pro(): void
    {
        $this->plan('basic');

        $this->api()->getJson('/api/achats')->assertForbidden()
            ->assertJsonPath('code', 'FONCTIONNALITE_NON_INCLUSE')->assertJsonPath('fonctionnalite', 'achats_fournisseurs');
        $this->api()->getJson('/api/fournisseurs')->assertForbidden();
        $this->api()->putJson('/api/boutique/fidelite', ['seuil' => 5, 'remise_pct' => 10])->assertForbidden();
        $this->api()->putJson('/api/boutique/objectif', ['objectif' => 100000])->assertForbidden();
        $this->api()->get('/api/rapports/mensuel', ['Accept' => 'application/json'])->assertForbidden();
        // Les droits du rôle passent ; des droits choisis, non.
        $this->api()->postJson('/api/equipe', ['nom' => 'Moussa', 'telephone' => '70000001', 'role' => 'gerant', 'password' => 'password123'])->assertCreated();
        $this->api()->postJson('/api/equipe', ['nom' => 'Fatou', 'telephone' => '70000002', 'role' => 'caissier', 'password' => 'password123', 'droits' => ['credit']])
            ->assertUnprocessable()->assertJsonValidationErrors('droits');
    }

    public function test_hors_du_plan_la_fidelite_ne_remise_rien(): void
    {
        $this->boutique->forceFill(['fidelite_seuil' => 2, 'fidelite_remise_pct' => 10])->save();
        $riz = $this->api()->postJson('/api/produits', ['nom' => 'Riz', 'prix_vente' => 5000, 'taux_tva' => 0, 'stock' => 50])->assertCreated()->json('id');
        $fatou = $this->api()->postJson('/api/clients', ['nom' => 'Fatou'])->assertCreated()->json('id');
        $this->plan('basic');

        $this->api()->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $riz, 'quantite' => 2]], 'moyen_paiement' => 'especes', 'client_id' => $fatou, 'remise_fidelite' => true,
        ])->assertCreated()->assertJsonPath('remise', 0);
    }

    public function test_la_console_retire_le_credit_et_le_back_office_a_un_plan(): void
    {
        $riz = $this->api()->postJson('/api/produits', ['nom' => 'Riz', 'prix_vente' => 5000, 'taux_tva' => 0, 'stock' => 50])->assertCreated()->json('id');
        $fatou = $this->api()->postJson('/api/clients', ['nom' => 'Fatou'])->assertCreated()->json('id');
        $this->plan('basic');
        $credit = fn () => $this->api()->postJson('/api/ventes', ['lignes' => [['produit_id' => $riz, 'quantite' => 1]], 'moyen_paiement' => 'credit_client', 'client_id' => $fatou]);

        // Cochés pour le Basic par défaut.
        $credit()->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->awa)->get('/tableau-de-bord')->assertOk();

        // L'exploitant les décoche dans la console.
        \App\Models\Plan::where('code', 'basic')->update(['fonctionnalites' => json_encode([])]);
        $credit()->assertForbidden()->assertJsonPath('message', 'La vente à crédit n’est pas incluse dans votre offre.');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->awa)->get('/tableau-de-bord')->assertRedirect(route('connexion'));
    }

    public function test_le_pro_ouvre_tout(): void
    {
        $this->plan('pro');

        $this->api()->getJson('/api/achats')->assertOk();
        $this->api()->putJson('/api/boutique/fidelite', ['seuil' => 5, 'remise_pct' => 10])->assertOk();
        $this->api()->postJson('/api/equipe', ['nom' => 'Fatou', 'telephone' => '70000002', 'role' => 'caissier', 'password' => 'password123', 'droits' => ['credit']])->assertCreated();
    }
}
