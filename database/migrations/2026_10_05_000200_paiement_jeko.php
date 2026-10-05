<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Demande d'abonnement réglée par Mobile Money via Jèko : la demande de
 * paiement créée chez Jèko, puis la transaction qui l'a payée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demandes_abonnement', function (Blueprint $t): void {
            $t->string('jeko_paiement_id', 64)->nullable()->unique()->after('preuve_note');
            $t->string('jeko_transaction_id', 64)->nullable()->after('jeko_paiement_id');
        });
    }

    public function down(): void
    {
        Schema::table('demandes_abonnement', function (Blueprint $t): void {
            $t->dropUnique(['jeko_paiement_id']);
            $t->dropColumn(['jeko_paiement_id', 'jeko_transaction_id']);
        });
    }
};
