<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Boutique;
use App\Support\Authorization\Permissions;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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

        $matrice = Permissions::roleMatrix();

        Boutique::withoutGlobalScopes()->chunk(50, function ($boutiques) use ($registrar, $matrice): void {
            foreach ($boutiques as $boutique) {
                $registrar->setPermissionsTeamId($boutique->id);

                foreach ($matrice as $role => $permissions) {
                    $roleModel = Role::firstOrCreate(
                        ['name' => $role, 'guard_name' => 'web', 'boutique_id' => $boutique->id],
                    );
                    $roleModel->syncPermissions($permissions);
                }
            }
        });

        $registrar->forgetCachedPermissions();

        $this->info('Rôles et permissions synchronisés pour '.Boutique::withoutGlobalScopes()->count().' boutique(s).');

        return self::SUCCESS;
    }
}
