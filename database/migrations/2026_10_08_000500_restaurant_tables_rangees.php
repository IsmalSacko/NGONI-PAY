<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Restaurant : une table rangée (pas utilisée ce soir) disparaît du plan de salle, sans être supprimée. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tables_restaurant', function (Blueprint $t): void {
            $t->boolean('rangee')->default(false)->after('ordre');
        });
    }

    public function down(): void
    {
        Schema::table('tables_restaurant', fn (Blueprint $t) => $t->dropColumn('rangee'));
    }
};
