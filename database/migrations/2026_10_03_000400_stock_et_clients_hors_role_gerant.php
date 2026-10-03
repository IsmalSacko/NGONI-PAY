<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * « Corriger le stock » devient un droit à part (Permissions::DROITS), et la
 * suppression d'un client revient au seul titulaire. Les gérants en place ont
 * déjà stocks.update en direct : ils gardent le nouveau droit, coché. Rejouer
 * les rôles retire clients.delete aux gérants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('ecaisse:sync-role-permissions');
    }

    public function down(): void {}
};
