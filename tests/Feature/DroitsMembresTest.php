<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Boutique;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\BoutiqueActive;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Droits par membre : le propriétaire (ou un admin) coche ce que chaque gérant
 * ou caissier peut voir et faire, et le serveur l'applique partout.
 */
class DroitsMembresTest extends TestCase
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

    private function api(User $user)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($user->createToken('t')->plainTextToken);
    }

    /** Ajoute un membre depuis l'application, comme le propriétaire. */
    private function ajouter(string $telephone, string $role, ?array $droits = null): User
    {
        $corps = ['nom' => 'Membre '.$telephone, 'telephone' => $telephone, 'role' => $role, 'password' => 'password123'];
        if ($droits !== null) {
            $corps['droits'] = $droits;
        }
        $id = $this->api($this->awa)->postJson('/api/equipe', $corps)->assertCreated()->json('data.id');

        return User::findOrFail($id);
    }

    public function test_un_gerant_ajoute_sans_droits_precis_a_ceux_du_role(): void
    {
        $gerant = $this->ajouter('70000001', 'gerant');

        $this->api($gerant)->getJson('/api/dashboard')->assertOk();
        $this->api($gerant)->getJson('/api/rapports')->assertOk();
    }

    public function test_un_gerant_sans_chiffre_d_affaires_ne_le_voit_nulle_part(): void
    {
        $gerant = $this->ajouter('70000001', 'gerant', ['articles', 'achats']);

        $this->api($gerant)->getJson('/api/dashboard')->assertForbidden();
        $this->api($gerant)->getJson('/api/rapports')->assertForbidden();
        $this->api($gerant)->getJson('/api/statistiques')->assertForbidden();
        $this->api($gerant)->getJson('/api/clotures')->assertForbidden();
        // Il gère toujours ses articles et ses achats.
        $this->api($gerant)->getJson('/api/produits')->assertOk();
        $this->api($gerant)->getJson('/api/fournisseurs')->assertOk();

        $permissions = $this->api($gerant)->getJson('/api/moi')->json('permissions');
        $this->assertNotContains('dashboard.view', $permissions);
        $this->assertContains('produits.update', $permissions);
    }

    public function test_le_proprietaire_change_les_droits_et_l_equipe_les_montre(): void
    {
        $gerant = $this->ajouter('70000001', 'gerant', []);
        $this->api($gerant)->getJson('/api/dashboard')->assertForbidden();

        $this->api($this->awa)->putJson("/api/equipe/{$gerant->id}", ['role' => 'gerant', 'droits' => ['chiffre_affaires']])->assertOk()
            ->assertJsonPath('data.droits', ['chiffre_affaires']);
        $this->api($gerant)->getJson('/api/dashboard')->assertOk();

        $equipe = $this->api($this->awa)->getJson('/api/equipe')->assertOk();
        $this->assertSame(array_keys(Permissions::DROITS), array_column($equipe->json('droits'), 'cle'));
        $this->assertSame(array_keys(Permissions::DROITS), collect($equipe->json('data'))->firstWhere('id', $this->awa->id)['droits'], 'un admin a tout');
    }

    public function test_un_caissier_peut_recevoir_le_chiffre_d_affaires(): void
    {
        $caissier = $this->ajouter('70000002', 'caissier');
        $this->api($caissier)->getJson('/api/dashboard')->assertForbidden();

        $this->api($this->awa)->putJson("/api/equipe/{$caissier->id}", ['role' => 'caissier', 'droits' => ['chiffre_affaires']])->assertOk();
        $this->api($caissier)->getJson('/api/dashboard')->assertOk();
    }

    public function test_seul_un_admin_regle_les_droits(): void
    {
        $gerant = $this->ajouter('70000001', 'gerant');
        $caissier = $this->ajouter('70000002', 'caissier');

        $this->api($gerant)->putJson("/api/equipe/{$caissier->id}", ['role' => 'caissier', 'droits' => ['chiffre_affaires']])->assertForbidden();
        $this->api($caissier)->getJson('/api/dashboard')->assertForbidden();
    }

    public function test_les_droits_restent_dans_leur_boutique(): void
    {
        // Gérant sans chiffre d'affaires chez Awa…
        $gerant = $this->ajouter('70000001', 'gerant', []);
        // …et gérant par défaut chez Ibrahim, avec le même compte.
        ['user' => $ibrahim] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Quincaillerie Ibrahim', 'pays' => 'ML', 'telephone' => '76008202', 'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Ibrahim',
        ]);
        $this->api($ibrahim)->postJson('/api/equipe', ['nom' => 'Même', 'telephone' => '70000001', 'role' => 'gerant'])->assertCreated()->assertJsonPath('cree', false);

        $this->api($gerant)->withHeader('X-Boutique', $this->boutique->id)->getJson('/api/dashboard')->assertForbidden();
        $this->api($gerant)->withHeader('X-Boutique', $ibrahim->boutique_id)->getJson('/api/dashboard')->assertOk();
    }

    public function test_le_back_office_ouvre_la_premiere_page_permise(): void
    {
        $gerant = $this->ajouter('70000001', 'gerant', ['articles', 'backoffice']);
        $this->app['auth']->forgetGuards();
        session([BoutiqueActive::CLE_SESSION => $this->boutique->id]);

        Livewire::actingAs($gerant)->test(Dashboard::class)->assertRedirect(route('produits.index'));
    }

    public function test_la_migration_garde_aux_gerants_existants_ce_qu_ils_avaient(): void
    {
        // Comme avant : un gérant n'a que son rôle, et le rôle porte tout.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        $gerant = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Ancien gérant', 'phone' => '+22370000009', 'password' => 'password123']);
        $gerant->assignRole('gerant');
        Role::where('name', 'gerant')->where('boutique_id', $this->boutique->id)->first()->givePermissionTo(Permissions::permissionsReglables());

        (require database_path('migrations/2026_10_02_000100_droits_par_membre.php'))->up();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        $role = Role::where('name', 'gerant')->where('boutique_id', $this->boutique->id)->first()->load('permissions');
        $this->assertNotContains('dashboard.view', $role->permissions->pluck('name')->all(), 'sorti du rôle');
        $this->api($gerant)->getJson('/api/dashboard')->assertOk();
    }
}
