<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Codes et formats vides enregistrés en '' : remis à NULL (unicité du code-barres). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['code_barre', 'code', 'format'] as $colonne) {
            DB::table('produits')->where($colonne, '')->update([$colonne => null]);
        }
    }

    public function down(): void {}
};
