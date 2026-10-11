<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le mode libre est un réglage de boutique qui vend pour de vrai : celles
 * qui l'ont déjà sortent du rodage, sans rien effacer.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('boutiques')->where('mode_libre', true)->where('mode_rodage', true)->update(['mode_rodage' => false]);
    }

    public function down(): void {}
};
