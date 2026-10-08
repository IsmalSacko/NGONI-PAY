<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Reçu d'un abonnement payé : son numéro, et la période que le paiement couvre. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demandes_abonnement', function (Blueprint $t): void {
            $t->string('recu_numero', 30)->nullable()->unique()->after('note_decision');
            $t->date('periode_debut')->nullable()->after('recu_numero');
            $t->date('periode_fin')->nullable()->after('periode_debut');
        });
    }

    public function down(): void
    {
        Schema::table('demandes_abonnement', function (Blueprint $t): void {
            $t->dropUnique(['recu_numero']);
            $t->dropColumn(['recu_numero', 'periode_debut', 'periode_fin']);
        });
    }
};
