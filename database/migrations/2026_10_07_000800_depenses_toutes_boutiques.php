<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Les dépenses (loyer, salaires, électricité…) ne sont plus réservées au
 * pressing : toute boutique les note, et le bilan les retire des recettes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('depenses_pressing', 'depenses');
    }

    public function down(): void
    {
        Schema::rename('depenses', 'depenses_pressing');
    }
};
