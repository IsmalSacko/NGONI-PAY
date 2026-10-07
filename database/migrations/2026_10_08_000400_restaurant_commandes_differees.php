<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Restaurant : commande différée (appel à 20 h pour 22 h) — elle part seule
 * en cuisine à [envoi_prevu_le] (30 min avant l'heure) ; [entree_file_le] :
 * le moment où elle entre dans la file, qui fixe son rang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_restaurant', function (Blueprint $t): void {
            $t->dateTime('envoi_prevu_le')->nullable()->after('heure_prevue');
            $t->dateTime('entree_file_le')->nullable()->after('envoi_prevu_le');
            $t->index('envoi_prevu_le');
        });
    }

    public function down(): void
    {
        Schema::table('commandes_restaurant', function (Blueprint $t): void {
            $t->dropIndex(['envoi_prevu_le']);
            $t->dropColumn(['envoi_prevu_le', 'entree_file_le']);
        });
    }
};
