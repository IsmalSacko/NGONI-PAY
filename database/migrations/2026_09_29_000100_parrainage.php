<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parrainage : le code de chaque compte, qui l'a parrainé, et quand le
 * parrain a été récompensé (au premier abonnement payé du filleul).
 *
 * `jours_offerts` : jours gagnés par un parrain qui n'avait pas d'abonnement
 * payé en cours ; ils s'ajoutent à sa prochaine approbation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('code_parrainage', 20)->nullable()->unique();
            $table->uuid('parraine_par')->nullable()->index();
            $table->timestamp('parrainage_recompense_le')->nullable();
            $table->foreign('parraine_par')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('abonnements', function (Blueprint $table) {
            $table->unsignedSmallInteger('jours_offerts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('abonnements', function (Blueprint $table) {
            $table->dropColumn('jours_offerts');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['parraine_par']);
            $table->dropColumn(['code_parrainage', 'parraine_par', 'parrainage_recompense_le']);
        });
    }
};
