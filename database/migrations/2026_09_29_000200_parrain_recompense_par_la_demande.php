<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le parrain qu'une demande approuvée a récompensé. Seule la première demande
 * payée d'un filleul le porte : la console la distingue ainsi des
 * renouvellements, qui ne rapportent rien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demandes_abonnement', function (Blueprint $table) {
            $table->uuid('parrain_recompense_id')->nullable();
            $table->foreign('parrain_recompense_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('demandes_abonnement', function (Blueprint $table) {
            $table->dropForeign(['parrain_recompense_id']);
            $table->dropColumn('parrain_recompense_id');
        });
    }
};
