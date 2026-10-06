<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pressing (offre Pro) : collecte à domicile et livraison au retour, casier
 * où le linge prêt est rangé, photos des défauts prises au dépôt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_pressing', function (Blueprint $t): void {
            $t->boolean('collecte')->default(false)->after('express');
            $t->boolean('livraison')->default(false)->after('collecte');
            $t->string('adresse', 255)->nullable()->after('livraison');
            $t->string('casier', 40)->nullable()->after('adresse');
            $t->json('photos')->nullable()->after('casier');
        });
    }

    public function down(): void
    {
        Schema::table('commandes_pressing', fn (Blueprint $t) => $t->dropColumn(['collecte', 'livraison', 'adresse', 'casier', 'photos']));
    }
};
