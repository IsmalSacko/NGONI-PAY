<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rend l'email facultatif (inscription par téléphone).
     *
     * Le schema builder gère `change()` nativement sur MySQL, PostgreSQL et
     * SQLite. La version précédente utilisait `ALTER TABLE ... MODIFY`, une
     * syntaxe propre à MySQL, qui faisait échouer la migration sur Postgres
     * (le SGBD de dev) comme sur SQLite (celui des tests).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
