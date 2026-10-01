<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Boutique;
use App\Support\Authorization\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rejoue le catalogue de permissions et la matrice des rôles sur chaque
 * boutique.
 *
 * Les rôles Spatie sont isolés par équipe (`boutique_id`, voir
 * config/permission.php) : une permission ajoutée à {@see Permissions} doit
 * donc être rattachée au rôle `admin`/`gerant`/`caissier` de CHAQUE boutique
 * déjà inscrite, pas seulement des suivantes. Idempotent — relançable sans
 * effet de bord après une boutique créée entre deux exécutions.
 */
class SyncRolePermissions extends Command
{
    protected $signature = 'ecaisse:sync-role-permissions';

    protected $description = 'Rejoue le catalogue de permissions et la matrice des rôles pour chaque boutique.';

    public function handle(): int
    {
        $registrar = app(PermissionRegistrar::class);

        foreach (Permissions::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $ids = Permission::where('guard_name', 'web')->pluck('id', 'name');
        $matrice = Permissions::roleMatrix();
        $roles = config('permission.table_names.roles');
        $pivot = config('permission.table_names.role_has_permissions');
        $equipe = config('permission.column_names.team_foreign_key');

        // Directement en base, boutique par boutique, et un seul vidage du
        // cache à la fin : syncPermissions() le vidait à chaque rôle, et sur
        // des centaines de boutiques chaque requête de l'application le
        // reconstruisait pendant toute la synchronisation (lenteur générale).
        Boutique::withoutGlobalScopes()->select('id')->chunk(100, function ($boutiques) use ($matrice, $ids, $roles, $pivot, $equipe): void {
            foreach ($boutiques as $boutique) {
                DB::transaction(function () use ($boutique, $matrice, $ids, $roles, $pivot, $equipe): void {
                    foreach ($matrice as $role => $permissions) {
                        $roleId = DB::table($roles)->where('name', $role)->where('guard_name', 'web')->where($equipe, $boutique->id)->value('id')
                            ?? DB::table($roles)->insertGetId(['name' => $role, 'guard_name' => 'web', $equipe => $boutique->id, 'created_at' => now(), 'updated_at' => now()]);
                        $voulues = collect($permissions)->map(fn ($p) => $ids[$p])->all();
                        $actuelles = DB::table($pivot)->where('role_id', $roleId)->pluck('permission_id')->all();
                        DB::table($pivot)->where('role_id', $roleId)->whereNotIn('permission_id', $voulues)->delete();
                        $manquantes = array_values(array_diff($voulues, $actuelles));
                        if ($manquantes !== []) {
                            DB::table($pivot)->insert(array_map(fn ($id) => ['role_id' => $roleId, 'permission_id' => $id], $manquantes));
                        }
                    }
                });
            }
        });

        $registrar->forgetCachedPermissions();

        $this->info('Rôles et permissions synchronisés pour '.Boutique::withoutGlobalScopes()->count().' boutique(s).');

        return self::SUCCESS;
    }
}
