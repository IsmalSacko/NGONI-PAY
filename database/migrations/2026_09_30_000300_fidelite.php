<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fidélité : « au bout de N achats, X % de remise ». Réglée par boutique
 * (null : pas de programme) ; la vente qui l'accorde est marquée, et le compte
 * d'achats du client repart d'elle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->unsignedSmallInteger('fidelite_seuil')->nullable();
            $table->unsignedTinyInteger('fidelite_remise_pct')->nullable();
        });

        Schema::table('ventes', function (Blueprint $table) {
            $table->boolean('remise_fidelite')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->dropColumn('remise_fidelite');
        });

        Schema::table('boutiques', function (Blueprint $table) {
            $table->dropColumn(['fidelite_seuil', 'fidelite_remise_pct']);
        });
    }
};
