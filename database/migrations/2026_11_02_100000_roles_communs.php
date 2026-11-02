<?php

declare(strict_types=1);

use App\Support\Authorization\RolesCommuns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Les rôles admin, gérant et caissier deviennent communs à toutes les
 * boutiques ({@see RolesCommuns}) : des milliers de copies identiques
 * ralentissaient chaque requête (+50 ms) et bloquaient plusieurs secondes
 * l'appel qui suivait un changement de droits.
 *
 * Chaque membre garde son rôle, dans la même boutique (la boutique est portée
 * par model_has_roles) ; ses droits propres (permissions directes) ne bougent
 * pas. Vérifié avant en production : les copies portaient exactement les
 * permissions de la matrice, et chaque attribution la boutique de son rôle.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roles = config('permission.table_names.roles');
        $modeles = config('permission.table_names.model_has_roles');
        $pivot = config('permission.table_names.role_has_permissions');
        $equipe = config('permission.column_names.team_foreign_key');
        $cle = config('permission.column_names.model_morph_key');

        RolesCommuns::assurer();
        $communs = DB::table($roles)->whereNull($equipe)->where('guard_name', 'web')->pluck('id', 'name');

        // Seules les copies d'un rôle commun sont remplacées ; un rôle inconnu
        // de la matrice resterait tel quel.
        $copies = DB::table($roles)->whereNotNull($equipe)->where('guard_name', 'web')
            ->whereIn('name', $communs->keys())->get(['id', 'name']);

        DB::transaction(function () use ($copies, $communs, $roles, $modeles, $pivot, $equipe, $cle): void {
            foreach ($copies->chunk(500) as $lot) {
                $versCommun = $lot->mapWithKeys(fn ($r) => [$r->id => $communs[$r->name]])->all();
                $ids = array_keys($versCommun);

                foreach (DB::table($modeles)->whereIn('role_id', $ids)->get() as $ligne) {
                    DB::table($modeles)->insertOrIgnore([
                        'role_id' => $versCommun[$ligne->role_id],
                        'model_type' => $ligne->model_type,
                        $cle => $ligne->{$cle},
                        $equipe => $ligne->{$equipe},
                    ]);
                }
                DB::table($modeles)->whereIn('role_id', $ids)->delete();
                DB::table($pivot)->whereIn('role_id', $ids)->delete();
                DB::table($roles)->whereIn('id', $ids)->delete();
            }
        });

        // Vidé après l'écriture, puis encore une fois : une requête qui
        // reconstruisait le cache pendant la migration (lent avec les copies)
        // a pu y remettre l'ancien état.
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
        if ($copies->isNotEmpty() && ! app()->runningUnitTests()) {
            sleep(8);
            $registrar->forgetCachedPermissions();
        }
    }

    // Pas de retour en arrière : les copies étaient identiques, les recréer
    // ne rendrait rien. La sauvegarde de la nuit garde l'état d'avant.
    public function down(): void {}
};
