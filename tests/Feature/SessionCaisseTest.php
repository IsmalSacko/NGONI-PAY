<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Services\SessionCaisseService;
use App\Services\VenteService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SessionCaisseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $result = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Test', 'pays' => 'ML', 'telephone' => '+223 76 00 00 00',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Aminata',
        ]);
        $this->admin = $result['user'];

        app(TenantContext::class)->setBoutique($result['boutique']->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($result['boutique']->id);
    }

    public function test_ouvrir_puis_ouvrir_a_nouveau_est_refuse(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/sessions-caisse', ['fond_initial' => 10000])->assertCreated();
        $this->withToken($token)->postJson('/api/sessions-caisse', ['fond_initial' => 5000])->assertUnprocessable();
    }

    public function test_fermer_calcule_lecart_a_partir_des_ventes_especes_de_la_session(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $ouverture = $this->withToken($token)->postJson('/api/sessions-caisse', ['fond_initial' => 10000])->assertCreated();
        $sessionId = $ouverture->json('id');

        $produit = Produit::create(['nom' => 'Riz', 'prix_vente' => 4750, 'taux_tva' => 18.00, 'stock' => 10]);

        // Une vente espèces (encaisse dans le tiroir) et une vente Orange Money
        // (n'affecte pas le tiroir) : seule la première doit compter dans
        // l'écart attendu à la fermeture.
        app(VenteService::class)->encaisser([
            'reference_locale' => (string) Str::uuid(),
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'moyen_paiement' => 'especes',
            'montant_recu' => 4750,
            'vendue_hors_ligne' => false,
        ], $this->admin);

        app(VenteService::class)->encaisser([
            'reference_locale' => (string) Str::uuid(),
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'moyen_paiement' => 'orange_money',
            'vendue_hors_ligne' => false,
        ], $this->admin);

        // Fond attendu : 10 000 (ouverture) + 4 750 (vente espèces) = 14 750.
        // Le caissier compte 14 700 dans le tiroir : écart de -50.
        $fermeture = $this->withToken($token)
            ->putJson("/api/sessions-caisse/{$sessionId}/fermer", ['fond_final' => 14700])
            ->assertOk();

        $fermeture->assertJsonPath('ecart', -50);
        $fermeture->assertJsonPath('statut', 'fermee');

        $this->assertDatabaseHas('ventes', ['moyen_paiement' => 'especes', 'session_caisse_id' => $sessionId]);
    }

    public function test_l_application_recoit_le_montant_attendu_et_le_fond_a_reprendre(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        // Aucune séance fermée encore : rien à proposer.
        $this->withToken($token)->getJson('/api/sessions-caisse/suggestion-ouverture')
            ->assertOk()->assertJsonPath('fond_suggere', null);

        $sessionId = $this->withToken($token)->postJson('/api/sessions-caisse', ['fond_initial' => 10000])->json('id');
        $produit = Produit::create(['nom' => 'Riz', 'prix_vente' => 4750, 'taux_tva' => 0, 'stock' => 10]);
        foreach (['especes', 'orange_money'] as $moyen) {
            app(VenteService::class)->encaisser([
                'reference_locale' => (string) Str::uuid(),
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
                'moyen_paiement' => $moyen,
                'montant_recu' => $moyen === 'especes' ? 4750 : null,
                'vendue_hors_ligne' => false,
            ], $this->admin);
        }

        // Pendant la séance : le tiroir devrait contenir 10 000 + 4 750 (Orange Money exclu).
        $this->withToken($token)->getJson('/api/sessions-caisse/courante')
            ->assertOk()
            ->assertJsonPath('total_especes', 4750)
            ->assertJsonPath('fond_attendu', 14750);

        $this->withToken($token)->putJson("/api/sessions-caisse/{$sessionId}/fermer", ['fond_final' => 14750])
            ->assertOk()->assertJsonPath('ecart', 0);

        // À la réouverture : on propose de reprendre ce qui est resté dans le tiroir.
        $this->withToken($token)->getJson('/api/sessions-caisse/suggestion-ouverture')
            ->assertOk()->assertJsonPath('fond_suggere', 14750);
    }

    public function test_fermer_une_session_deja_fermee_est_refuse(): void
    {
        $session = app(SessionCaisseService::class)->ouvrir($this->admin, 5000);
        app(SessionCaisseService::class)->fermer($session, 5000);

        $token = $this->admin->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->putJson("/api/sessions-caisse/{$session->id}/fermer", ['fond_final' => 5000])
            ->assertUnprocessable();
    }

    public function test_un_caissier_ne_peut_pas_fermer_la_session_dun_collegue(): void
    {
        $collegue = User::create([
            'boutique_id' => $this->admin->boutique_id,
            'name' => 'Collègue',
            'phone' => '+22379000000',
            'password' => bcrypt('password123'),
        ]);
        $collegue->assignRole('caissier');

        $session = app(SessionCaisseService::class)->ouvrir($collegue, 5000);

        $autreCaissier = User::create([
            'boutique_id' => $this->admin->boutique_id,
            'name' => 'Autre caissier',
            'phone' => '+22379000001',
            'password' => bcrypt('password123'),
        ]);
        $autreCaissier->assignRole('caissier');

        $token = $autreCaissier->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->putJson("/api/sessions-caisse/{$session->id}/fermer", ['fond_final' => 5000])
            ->assertForbidden();
    }
}
