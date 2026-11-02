<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Authorization\Permissions;
use App\Support\Authorization\RolesCommuns;
use Illuminate\Console\Command;

/**
 * Rejoue le catalogue de permissions et la matrice des rôles.
 *
 * Les rôles `admin`/`gerant`/`caissier` sont communs à toutes les boutiques
 * ({@see RolesCommuns}) : une permission ajoutée à {@see Permissions} se
 * rattache une fois, à eux trois, et vaut pour chaque boutique. Idempotent.
 */
class SyncRolePermissions extends Command
{
    protected $signature = 'ecaisse:sync-role-permissions';

    protected $description = 'Rejoue le catalogue de permissions et la matrice des rôles communs.';

    public function handle(): int
    {
        $this->info(RolesCommuns::assurer()
            ? 'Rôles communs et permissions mis à jour.'
            : 'Rôles communs et permissions déjà à jour.');

        return self::SUCCESS;
    }
}
