<?php

declare(strict_types=1);

use App\Models\Boutique;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Droits par membre (Permissions::DROITS) : le chiffre d'affaires, les
 * articles, les annulations, les achats et le back-office sortent du rôle
 * gérant pour être accordés membre par membre. Chaque gérant déjà en place
 * les reçoit d'abord directement, puis les rôles sont rejoués : personne ne
 * perd ce qu'il avait, pas même un instant.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Les permissions doivent exister avant d'être données.
        Artisan::call('ecaisse:sync-role-permissions');

        $registrar = app(PermissionRegistrar::class);
        $roles = config('permission.table_names.roles');
        $modeles = config('permission.table_names.model_has_roles');
        $equipe = config('permission.column_names.team_foreign_key');

        Boutique::withoutGlobalScopes()->chunk(50, function ($boutiques) use ($registrar, $roles, $modeles, $equipe): void {
            foreach ($boutiques as $boutique) {
                $registrar->setPermissionsTeamId($boutique->id);
                $gerants = DB::table($modeles)
                    ->join($roles, "{$roles}.id", '=', "{$modeles}.role_id")
                    ->where("{$modeles}.{$equipe}", $boutique->id)
                    ->where("{$roles}.name", 'gerant')
                    ->pluck("{$modeles}.".config('permission.column_names.model_morph_key'));
                foreach (User::whereIn('id', $gerants)->get() as $gerant) {
                    $gerant->givePermissionTo(Permissions::permissionsReglables());
                }
            }
        });

        // Puis les rôles, désormais sans ces permissions.
        Artisan::call('ecaisse:sync-role-permissions');
        $registrar->forgetCachedPermissions();
    }

    public function down(): void {}
};
