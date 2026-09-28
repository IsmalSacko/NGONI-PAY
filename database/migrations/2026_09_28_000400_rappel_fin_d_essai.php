<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date de fin pour laquelle le rappel « votre essai se termine » est parti.
 *
 * Une date et non un booléen : un essai prolongé depuis la console change de
 * fin, et la nouvelle échéance a droit à son propre rappel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abonnements', function (Blueprint $table) {
            $table->date('rappel_fin_pour')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('abonnements', function (Blueprint $table) {
            $table->dropColumn('rappel_fin_pour');
        });
    }
};
