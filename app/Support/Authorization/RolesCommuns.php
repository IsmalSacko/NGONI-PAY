<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Les trois rôles (admin, gérant, caissier), communs à toutes les boutiques.
 *
 * Ils étaient copiés pour chaque boutique (rôle d'équipe Spatie) : des
 * milliers de rôles identiques en novembre 2026. Spatie garde tous les rôles
 * avec leurs permissions dans un seul cache (des mégaoctets), relu à chaque
 * requête (+50 ms) et reconstruit après chaque changement de droits
 * (plusieurs secondes pour l'appel suivant). Un rôle sans boutique
 * (`boutique_id` nul) vaut dans toutes : c'est l'attribution (model_has_roles)
 * qui porte la boutique, et les droits propres à un membre restent des
 * permissions directes.
 */
final class RolesCommuns
{
    /**
     * Crée les rôles qui manquent et aligne leurs permissions sur la matrice,
     * sans toucher à ce qui est déjà juste : ni DELETE général (deux
     * inscriptions à la même seconde s'y bloquaient, deadlock vu en prod les 8
     * et 9 octobre 2026), ni vidage du cache quand rien n'a changé.
     *
     * @return bool vrai si quelque chose a changé
     */
    public static function assurer(): bool
    {
        foreach (Permissions::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $ids = Permission::where('guard_name', 'web')->pluck('id', 'name');
        $roles = config('permission.table_names.roles');
        $pivot = config('permission.table_names.role_has_permissions');
        $equipe = config('permission.column_names.team_foreign_key');
        $change = false;

        foreach (Permissions::roleMatrix() as $nom => $permissions) {
            $roleId = DB::table($roles)->where('name', $nom)->where('guard_name', 'web')->whereNull($equipe)->value('id');
            if ($roleId === null) {
                $roleId = DB::table($roles)->insertGetId(['name' => $nom, 'guard_name' => 'web', $equipe => null, 'created_at' => now(), 'updated_at' => now()]);
                $change = true;
            }

            $voulues = collect($permissions)->map(fn (string $p) => $ids[$p])->all();
            $actuelles = DB::table($pivot)->where('role_id', $roleId)->pluck('permission_id')->all();

            $enTrop = array_values(array_diff($actuelles, $voulues));
            if ($enTrop !== []) {
                DB::table($pivot)->where('role_id', $roleId)->whereIn('permission_id', $enTrop)->delete();
                $change = true;
            }
            $manquantes = array_values(array_diff($voulues, $actuelles));
            if ($manquantes !== []) {
                DB::table($pivot)->insertOrIgnore(array_map(fn ($id) => ['role_id' => $roleId, 'permission_id' => $id], $manquantes));
                $change = true;
            }
        }

        if ($change) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $change;
    }
}
