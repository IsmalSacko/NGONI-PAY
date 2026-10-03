<?php

declare(strict_types=1);

use App\Models\Boutique;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * « Voir toutes les ventes » sort du chiffre d'affaires (Permissions::DROITS).
 * Chaque gérant en place le reçoit, y compris celui à qui le propriétaire a
 * retiré le chiffre d'affaires : un gérant contrôle les tickets de la
 * boutique. Le propriétaire peut ensuite le lui retirer.
 */
return new class extends Migration
{
    public function up(): void
    {
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
                    $gerant->givePermissionTo('ventes.view_all');
                }
            }
        });

        $registrar->forgetCachedPermissions();
    }

    public function down(): void {}
};
