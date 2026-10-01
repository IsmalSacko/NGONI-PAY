<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Boutiques\Index as BoutiquesIndex;
use App\Models\Boutique;
use App\Models\Client;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Services\EquipeService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Qui voit quoi : admin (tout), gérant (tout sauf l'équipe et l'abonnement),
 * caissier (l'application, ses propres ventes, pas de back-office).
 */
class EquipeEtRolesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $gerant;

    private User $caissier;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();

        ['user' => $this->admin, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);

        $this->dansLaBoutique();
        $this->gerant = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Gérant', 'phone' => '+22370000001', 'password' => 'password123']);
        $this->gerant->assignRole('gerant');
        // Comme un gérant ajouté depuis l'équipe : ses droits par défaut.
        EquipeService::droitsParDefaut($this->gerant, 'gerant');
        $this->caissier = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Caissier', 'phone' => '+22370000002', 'password' => 'password123']);
        $this->caissier->assignRole('caissier');
    }

    private function dansLaBoutique(): void
    {
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
    }

    private function api(User $user)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($user->createToken('t')->plainTextToken);
    }

    private function vendre(User $user): string
    {
        return $this->api($user)->postJson('/api/ventes', [
            'reference_locale' => (string) Str::uuid(),
            'lignes' => [['libelle' => 'Repassage', 'prix_unitaire' => 1500, 'quantite' => 1]],
            'moyen_paiement' => 'especes',
        ])->assertCreated()->json('id');
    }

    public function test_le_caissier_ne_voit_que_ses_ventes_le_gerant_toutes(): void
    {
        $venteAdmin = $this->vendre($this->admin);
        $venteCaissier = $this->vendre($this->caissier);

        $this->api($this->caissier)->getJson('/api/ventes')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $venteCaissier);
        $this->api($this->caissier)->getJson("/api/ventes/{$venteAdmin}")->assertNotFound();

        $this->api($this->gerant)->getJson('/api/ventes')->assertOk()->assertJsonCount(2, 'data')
            // Pressing Awa → PA, année, rang dans la boutique.
            ->assertJsonPath('data.0.numero_facture', 'PA-'.now()->format('Y').'-0002')
            ->assertJsonPath('data.1.numero_facture', 'PA-'.now()->format('Y').'-0001');
    }

    public function test_le_caissier_n_a_ni_pilotage_ni_equipe(): void
    {
        $this->api($this->caissier)->getJson('/api/dashboard')->assertForbidden();
        $this->api($this->caissier)->getJson('/api/equipe')->assertForbidden();
        $this->api($this->caissier)->getJson('/api/clients')->assertOk();
    }

    public function test_le_gerant_voit_l_equipe_sans_la_modifier(): void
    {
        $this->api($this->gerant)->getJson('/api/equipe')->assertOk()->assertJsonCount(3, 'data');
        $this->api($this->gerant)->postJson('/api/equipe', ['nom' => 'X', 'telephone' => '70000009', 'role' => 'caissier'])->assertForbidden();
        $this->api($this->gerant)->putJson("/api/equipe/{$this->caissier->id}", ['role' => 'admin'])->assertForbidden();
    }

    public function test_l_admin_gere_l_equipe_depuis_l_application(): void
    {
        // L'essai s'arrête à 3 membres, déjà atteints : Pro, équipe illimitée.
        $this->admin->abonnement()->update(['plan' => 'pro', 'fin' => now()->addMonth()->toDateString()]);

        $ajout = $this->api($this->admin)->postJson('/api/equipe', [
            'nom' => 'Moussa', 'telephone' => '70 00 00 05', 'role' => 'caissier',
        ])->assertCreated()->assertJsonPath('cree', true)->assertJsonPath('data.role', 'caissier');

        $provisoire = $ajout->json('mot_de_passe_provisoire');
        $this->assertNotEmpty($provisoire);
        $this->postJson('/api/connexion', ['telephone' => '70000005', 'pays' => 'ML', 'password' => $provisoire])->assertOk();

        $id = $ajout->json('data.id');
        $this->api($this->admin)->putJson("/api/equipe/{$id}", ['role' => 'gerant'])->assertOk()->assertJsonPath('data.role', 'gerant');

        // Ni son propre rôle, ni le propriétaire.
        $this->api($this->admin)->putJson("/api/equipe/{$this->admin->id}", ['role' => 'caissier'])->assertUnprocessable();

        $this->api($this->admin)->deleteJson("/api/equipe/{$id}")->assertNoContent();
        $this->api($this->admin)->getJson('/api/equipe')->assertJsonCount(3, 'data');
    }

    public function test_le_back_office_est_ferme_au_caissier(): void
    {
        Livewire::test(Login::class)
            ->set('telephone', '70000002')->set('password', 'password123')
            ->call('connexion')
            ->assertHasErrors('telephone')
            ->assertNoRedirect();
        $this->assertGuest();

        // Session ouverte par un autre moyen : renvoyé, avec l'explication.
        $this->actingAs($this->caissier)->get('/clients')->assertRedirect('/connexion');
        $this->assertGuest();

        $this->actingAs($this->gerant)->get('/clients')->assertOk();
    }

    public function test_ouvrir_une_boutique_depuis_le_back_office_selon_le_plan(): void
    {
        $this->actingAs($this->admin);
        $this->dansLaBoutique();

        // Essai : une seule boutique.
        Livewire::test(BoutiquesIndex::class)
            ->call('nouvelle')->set('nom', 'Annexe')->call('creer')
            ->assertSet('alerte', fn ($a) => str_contains((string) $a, 'plan'));
        $this->assertSame(1, $this->admin->boutiquesPossedees()->count());

        $this->admin->abonnement()->update(['plan' => 'pro', 'fin' => now()->addMonth()->toDateString()]);

        Livewire::test(BoutiquesIndex::class)
            ->call('nouvelle')->set('nom', 'Annexe')->set('pays', 'CI')->call('creer')
            ->assertRedirect(route('boutiques.index'));

        $annexe = Boutique::where('nom', 'Annexe')->firstOrFail();
        $this->assertSame('XOF', $annexe->devise);
        $this->assertSame($annexe->id, session('boutique_active'));
        $this->get('/boutiques')->assertOk()->assertSee('Annexe');
    }

    public function test_les_clients_portent_email_notes_et_achats(): void
    {
        $client = $this->api($this->admin)->postJson('/api/clients', [
            'nom' => 'Fatou', 'telephone' => '70112233', 'email' => 'fatou@example.com', 'notes' => 'Préfère le repassage léger',
        ])->assertCreated()->json('id');

        $this->api($this->admin)->postJson('/api/ventes', [
            'reference_locale' => (string) Str::uuid(),
            'client_id' => $client,
            'lignes' => [['libelle' => 'Repassage', 'prix_unitaire' => 2500, 'quantite' => 2]],
            'moyen_paiement' => 'especes',
        ])->assertCreated();

        $this->api($this->admin)->getJson('/api/clients')->assertOk()
            ->assertJsonPath('data.0.email', 'fatou@example.com')
            ->assertJsonPath('data.0.achats', 1)
            ->assertJsonPath('data.0.total_achats', 5000);
    }

    public function test_une_vente_ne_peut_viser_le_client_d_une_autre_boutique(): void
    {
        ['boutique' => $autre] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Autre', 'pays' => 'ML', 'telephone' => '76999999',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Autre',
        ]);
        app(TenantContext::class)->setBoutique($autre->id);
        $etranger = Client::create(['nom' => 'Client ailleurs']);

        $this->api($this->admin)->postJson('/api/ventes', [
            'reference_locale' => (string) Str::uuid(),
            'client_id' => $etranger->id,
            'lignes' => [['libelle' => 'Repassage', 'prix_unitaire' => 1500, 'quantite' => 1]],
            'moyen_paiement' => 'especes',
        ])->assertUnprocessable()->assertJsonValidationErrors('client_id');
    }

    public function test_l_admin_regle_pays_et_devise_de_sa_boutique(): void
    {
        // Boutique au Mali, commerçant joignable en France : la devise se choisit.
        $this->api($this->admin)->putJson('/api/boutique', [
            'nom' => 'Pharmacie Les Castors', 'pays' => 'FR', 'telephone' => '06 05 75 84 94', 'adresse' => 'Paris',
        ])->assertOk()->assertJsonPath('data.devise', 'EUR')->assertJsonPath('data.telephone', '+33605758494');

        $this->api($this->admin)->putJson('/api/boutique', ['nom' => 'Pharmacie Les Castors', 'pays' => 'ML', 'devise' => 'EUR'])
            ->assertOk()->assertJsonPath('data.devise', 'EUR')->assertJsonPath('data.pays', 'ML');

        $this->api($this->admin)->putJson('/api/boutique', ['nom' => 'X', 'pays' => 'ML', 'devise' => 'ABC'])
            ->assertUnprocessable()->assertJsonValidationErrors('devise');

        $this->api($this->gerant)->putJson('/api/boutique', ['nom' => 'Pirate', 'pays' => 'ML'])->assertForbidden();
        $this->api($this->caissier)->putJson('/api/boutique', ['nom' => 'Pirate', 'pays' => 'ML'])->assertForbidden();

        // /moi rend la nouvelle devise à l'application.
        $this->api($this->admin)->getJson('/api/moi')->assertJsonPath('user.boutique.devise', 'EUR');
    }

    public function test_les_reglages_depuis_le_back_office(): void
    {
        $this->actingAs($this->admin);
        $this->dansLaBoutique();

        Livewire::test(BoutiquesIndex::class)
            ->call('ouvrirReglages')
            ->assertSet('reglages.devise', 'XOF')
            ->set('reglages.devise', 'EUR')
            ->set('reglages.adresse', 'Bamako')
            ->call('enregistrerReglages')
            ->assertRedirect(route('boutiques.index'));

        $this->assertSame('EUR', $this->boutique->fresh()->devise);
        $this->assertSame('Bamako', $this->boutique->fresh()->adresse);

        $this->actingAs($this->gerant);
        Livewire::test(BoutiquesIndex::class)->call('ouvrirReglages')->assertForbidden();
    }

    /**
     * Les actions Livewire (/livewire/update) ne passent pas par le
     * middleware `tenant` : le composant doit reposer le contexte lui-même.
     */
    public function test_les_actions_du_back_office_retrouvent_la_boutique_active(): void
    {
        $this->actingAs($this->admin);
        $this->dansLaBoutique();
        $composant = Livewire::test(BoutiquesIndex::class);

        // Requête suivante : plus aucun contexte, comme en production.
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $this->admin->unsetRelation('roles')->unsetRelation('permissions');

        $composant->call('ouvrirReglages')->assertSet('reglagesOuverts', true)->assertSet('reglages.devise', 'XOF');
    }

    public function test_seul_l_admin_ouvre_une_nouvelle_boutique(): void
    {
        $this->admin->abonnement()->update(['plan' => 'pro', 'fin' => now()->addMonth()->toDateString()]);

        $this->api($this->caissier)->postJson('/api/boutiques', ['nom' => 'Ma boutique'])
            ->assertForbidden()->assertJsonPath('code', 'ROLE_INSUFFISANT');
        $this->api($this->gerant)->postJson('/api/boutiques', ['nom' => 'Ma boutique'])->assertForbidden();
        $this->assertSame(1, Boutique::withoutGlobalScopes()->count());

        $this->api($this->admin)->postJson('/api/boutiques', ['nom' => 'Annexe'])->assertCreated();

        // Back-office : le gérant n'a ni le bouton ni l'action.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->gerant);
        $this->dansLaBoutique();
        $this->get('/boutiques')->assertOk()->assertDontSee('+ Nouvelle boutique');
        Livewire::test(BoutiquesIndex::class)->call('nouvelle')->assertForbidden();
    }
}
