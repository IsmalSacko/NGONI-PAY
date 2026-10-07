<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Restaurant : l'heure à laquelle la cuisine annonce la commande prête
 * (« prête vers 20:15 »), et le départ en livraison.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_restaurant', function (Blueprint $t): void {
            $t->dateTime('prete_vers')->nullable()->after('heure_prevue');
            $t->dateTime('en_livraison_le')->nullable()->after('prete_vers');
        });
    }

    public function down(): void
    {
        Schema::table('commandes_restaurant', fn (Blueprint $t) => $t->dropColumn(['prete_vers', 'en_livraison_le']));
    }
};
