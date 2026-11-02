<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les rôles admin, gérant et caissier sont communs à toutes les boutiques :
 * une copie par boutique alourdissait chaque requête et bloquait plusieurs
 * secondes l'appel suivant un changement de droits.
 */
class RolesCommunsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{user: User, boutique: Boutique} */
    private function inscrire(string $nom, string $telephone): array
    {
        return app(BoutiqueRegistrationService::class)->register([
            'nom' => $nom, 'pays' => 'ML', 'telephone' => $telephone,
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => $nom,
        ]);
    }

    private function dans(Boutique $boutique, User ...$membres): void
    {
        app(TenantContext::class)->setBoutique($boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($boutique->id);
        foreach ($membres as $membre) {
            $membre->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    public function test_deux_boutiques_partagent_trois_roles_sans_partager_les_droits(): void
    {
        ['user' => $awa, 'boutique' => $pressing] = $this->inscrire('Pressing Awa', '76008201');
        ['user' => $issa, 'boutique' => $quincaillerie] = $this->inscrire('Quincaillerie Issa', '76008202');

        $this->assertSame(3, DB::table('roles')->count());
        $this->assertSame(0, DB::table('roles')->whereNotNull('boutique_id')->count(), 'aucune copie par boutique');

        // Le rôle est commun, l'attribution reste à sa boutique.
        $this->dans($pressing, $awa, $issa);
        $this->assertTrue($awa->hasRole('admin'));
        $this->assertTrue($awa->can('utilisateurs.view'));
        $this->assertFalse($issa->hasRole('admin'));
        $this->assertFalse($issa->can('utilisateurs.view'));

        $this->dans($quincaillerie, $awa, $issa);
        $this->assertFalse($awa->can('utilisateurs.view'));
        $this->assertTrue($issa->can('utilisateurs.view'));
    }

    public function test_la_migration_remplace_les_copies_sans_rien_changer_aux_membres(): void
    {
        ['user' => $awa, 'boutique' => $pressing] = $this->inscrire('Pressing Awa', '76008201');
        $this->dans($pressing);
        $caissier = User::create(['boutique_id' => $pressing->id, 'name' => 'Moussa', 'phone' => '+22370000002', 'password' => 'password123']);
        $caissier->assignRole('caissier');
        $caissier->givePermissionTo('ventes.credit');

        // L'état d'avant : des rôles copiés pour la boutique, avec leurs
        // permissions, et les membres rattachés à ces copies.
        foreach (Permissions::roleMatrix() as $nom => $permissions) {
            $commun = DB::table('roles')->where('name', $nom)->whereNull('boutique_id')->value('id');
            $copie = DB::table('roles')->insertGetId(['name' => $nom, 'guard_name' => 'web', 'boutique_id' => $pressing->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('role_has_permissions')->insert(DB::table('role_has_permissions')->where('role_id', $commun)->pluck('permission_id')
                ->map(fn ($p) => ['role_id' => $copie, 'permission_id' => $p])->all());
            DB::table('model_has_roles')->where('role_id', $commun)->update(['role_id' => $copie]);
        }
        $this->assertSame(6, DB::table('roles')->count());

        (require database_path('migrations/2026_11_02_100000_roles_communs.php'))->up();

        $this->assertSame(3, DB::table('roles')->count());
        $this->assertSame(0, DB::table('roles')->whereNotNull('boutique_id')->count());
        $this->assertSame(0, DB::table('role_has_permissions')->whereNotIn('role_id', DB::table('roles')->select('id'))->count());

        $this->dans($pressing, $awa, $caissier);
        $this->assertTrue($awa->hasRole('admin'));
        $this->assertTrue($awa->can('utilisateurs.view'));
        $this->assertTrue($caissier->hasRole('caissier'));
        $this->assertTrue($caissier->can('ventes.credit'), 'ses droits propres restent');
        $this->assertFalse($caissier->can('utilisateurs.view'));
        $this->assertSame(1, DB::table('model_has_roles')->where('model_id', $caissier->id)->where('boutique_id', $pressing->id)->count());
    }
}
