<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tout l'abonnement depuis la Console Ngoni (contrat v1.1) : la référence du
 * paiement saisie à la validation, le nom de qui a décidé (« Console ·
 * Ismaila »), et le journal des accords et révocations d'un compte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demandes_abonnement', function (Blueprint $t): void {
            $t->string('reference', 120)->nullable()->after('pawapay_deposit_id');
            $t->string('decide_par_nom', 120)->nullable()->after('decide_par');
        });

        Schema::create('journal_abonnements', function (Blueprint $t): void {
            $t->id();
            $t->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // accord | revocation
            $t->string('type', 20);
            $t->string('plan', 30)->nullable();
            $t->date('debut')->nullable();
            $t->date('fin')->nullable();
            $t->unsignedInteger('montant')->nullable();
            $t->string('devise', 3)->nullable();
            $t->string('moyen', 30)->nullable();
            $t->string('reference', 120)->nullable();
            // Accord payé : la demande qui porte son reçu.
            $t->foreignId('demande_id')->nullable()->constrained('demandes_abonnement')->nullOnDelete();
            $t->string('par', 120)->nullable();
            $t->string('note', 500)->nullable();
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_abonnements');
        Schema::table('demandes_abonnement', fn (Blueprint $t) => $t->dropColumn(['reference', 'decide_par_nom']));
    }
};
