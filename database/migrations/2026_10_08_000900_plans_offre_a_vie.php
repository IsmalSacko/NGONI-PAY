<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Abonnement à vie, tenu par l'exploitant dans la console (Plans et tarifs)
 * plutôt que dans le code : prix, texte et période de l'offre, par plan.
 * Reprend les valeurs de lancement (config/conditions.php jusqu'ici).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t): void {
            $t->unsignedBigInteger('prix_a_vie')->nullable()->after('max_membres');
            $t->string('texte_a_vie', 255)->nullable()->after('prix_a_vie');
            $t->date('a_vie_debut')->nullable()->after('texte_a_vie');
            $t->date('a_vie_fin')->nullable()->after('a_vie_debut');
        });

        $lancement = ['a_vie_debut' => '2026-09-01', 'a_vie_fin' => '2027-02-28'];
        DB::table('plans')->where('code', 'basic')->update([...$lancement, 'prix_a_vie' => 100000, 'texte_a_vie' => 'Toutes les fonctions du Basic, sans jamais renouveler.']);
        DB::table('plans')->where('code', 'pro')->update([...$lancement, 'prix_a_vie' => 250000, 'texte_a_vie' => 'Tout le Pro, une fois pour toutes.']);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $t): void {
            $t->dropColumn(['prix_a_vie', 'texte_a_vie', 'a_vie_debut', 'a_vie_fin']);
        });
    }
};
