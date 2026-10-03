<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Services\SessionCaisseService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * « Supprimer » un équipier (propriétaire seul), à côté de « Retirer » qui
 * reste tel quel : le compte ne se connecte plus, le numéro est libéré, et
 * ses ventes gardent leur caissier.
 */
class SuppressionEquipierTest extends TestCase
{
    use RefreshDatabase;

    private User $proprietaire;

    private User $caissier;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        ['user' => $this->proprietaire, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        $this->dansLaBoutique();
        $this->caissier = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Moussa Caissier', 'phone' => '+22370000002', 'password' => 'password123']);
        $this->caissier->assignRole('caissier');
        // Le crédit, le propriétaire l'accorde (Permissions::DROITS) : un caissier ne l'a pas d'office.
        $this->caissier->givePermissionTo(['ventes.credit', 'ventes.montant_libre']);
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

    public function test_le_proprietaire_supprime_un_caissier_et_ses_ventes_gardent_son_nom(): void
    {
        $vente = $this->vendre($this->caissier);
        $jeton = $this->caissier->createToken('telephone')->plainTextToken;
        // Témoin : avant, la même connexion marche.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/connexion', ['telephone' => '70000002', 'pays' => 'ML', 'password' => 'password123'])->assertOk();

        $this->api($this->proprietaire)->deleteJson("/api/equipe/{$this->caissier->id}/compte")->assertNoContent();

        // Il ne se connecte plus, et son ancien jeton ne marche plus.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/connexion', ['telephone' => '70000002', 'pays' => 'ML', 'password' => 'password123'])->assertStatus(422);
        $this->withToken($jeton)->getJson('/api/ventes')->assertUnauthorized();

        // Il n'est plus dans l'équipe ; sa vente reste, avec son nom et son numéro de facture.
        $this->api($this->proprietaire)->getJson('/api/equipe')->assertOk()->assertJsonCount(1, 'data');
        $this->api($this->proprietaire)->getJson("/api/ventes/{$vente}")->assertOk()
            ->assertJsonPath('caissier.name', 'Moussa Caissier')
            ->assertJsonPath('numero_facture', 'PA-'.now()->format('Y').'-0001');
        $this->assertSoftDeleted('users', ['id' => $this->caissier->id]);

        // Son numéro est libre : il peut revenir, avec un compte neuf.
        $this->api($this->proprietaire)->postJson('/api/equipe', ['nom' => 'Moussa', 'telephone' => '70000002', 'role' => 'caissier'])
            ->assertCreated()->assertJsonPath('cree', true);
    }

    public function test_un_admin_qui_n_est_pas_le_proprietaire_ne_peut_pas_supprimer(): void
    {
        $admin = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Admin 2', 'phone' => '+22370000003', 'password' => 'password123']);
        $this->dansLaBoutique();
        $admin->assignRole('admin');

        $this->api($admin)->deleteJson("/api/equipe/{$this->caissier->id}/compte")->assertStatus(422);
        $this->assertNotSoftDeleted('users', ['id' => $this->caissier->id]);
    }

    public function test_refuse_s_il_travaille_aussi_dans_une_autre_boutique(): void
    {
        ['boutique' => $autre] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Quincaillerie', 'pays' => 'ML', 'telephone' => '76008202',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Ibrahim',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($autre->id);
        $this->caissier->unsetRelation('roles')->assignRole('caissier');

        $this->api($this->proprietaire)->deleteJson("/api/equipe/{$this->caissier->id}/compte")->assertStatus(422)
            ->assertJsonPath('errors.membre.0', fn (string $m) => str_contains($m, 'autre boutique'));
        $this->assertNotSoftDeleted('users', ['id' => $this->caissier->id]);
    }

    public function test_refuse_si_sa_caisse_est_ouverte(): void
    {
        $this->dansLaBoutique();
        app(SessionCaisseService::class)->ouvrir($this->caissier, 10000);

        $this->api($this->proprietaire)->deleteJson("/api/equipe/{$this->caissier->id}/compte")->assertStatus(422)
            ->assertJsonPath('errors.membre.0', fn (string $m) => str_contains($m, 'caisse ouverte'));
    }

    public function test_le_proprietaire_ne_peut_pas_supprimer_son_propre_compte(): void
    {
        $this->api($this->proprietaire)->deleteJson("/api/equipe/{$this->proprietaire->id}/compte")->assertStatus(422);
    }

    public function test_retirer_reste_tel_quel_le_compte_existe_toujours(): void
    {
        $this->api($this->proprietaire)->deleteJson("/api/equipe/{$this->caissier->id}")->assertNoContent();
        $this->assertNotSoftDeleted('users', ['id' => $this->caissier->id]);
        $this->assertSame('+22370000002', $this->caissier->fresh()->phone);
    }
}
