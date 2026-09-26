<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Nouvelles permissions (ventes.view_all, backoffice.access) rattachées aux
 * rôles de chaque boutique déjà inscrite. La commande est idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('ecaisse:sync-role-permissions');
    }

    public function down(): void {}
};
