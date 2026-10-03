<?php

declare(strict_types=1);

use App\Models\Boutique;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Remises, crédit et montant libre deviennent des droits (Permissions::DROITS).
 * Jusqu'ici tout vendeur pouvait tout : chaque gérant et caissier en place les
 * reçoit, pour que rien ne change au comptoir du jour au lendemain. Le
 * propriétaire les retire ensuite à qui il veut.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['ventes.remise', 'ventes.credit', 'ventes.montant_libre'];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $roles = config('permission.table_names.roles');
        $modeles = config('permission.table_names.model_has_roles');
        $equipe = config('permission.column_names.team_foreign_key');

        Boutique::withoutGlobalScopes()->chunk(50, function ($boutiques) use ($registrar, $roles, $modeles, $equipe): void {
            foreach ($boutiques as $boutique) {
                $registrar->setPermissionsTeamId($boutique->id);
                $membres = DB::table($modeles)
                    ->join($roles, "{$roles}.id", '=', "{$modeles}.role_id")
                    ->where("{$modeles}.{$equipe}", $boutique->id)
                    ->whereIn("{$roles}.name", ['gerant', 'caissier'])
                    ->pluck("{$modeles}.".config('permission.column_names.model_morph_key'));
                foreach (User::whereIn('id', $membres)->get() as $membre) {
                    $membre->givePermissionTo(self::PERMISSIONS);
                }
            }
        });

        Artisan::call('ecaisse:sync-role-permissions');
        $registrar->forgetCachedPermissions();
    }

    public function down(): void {}
};
