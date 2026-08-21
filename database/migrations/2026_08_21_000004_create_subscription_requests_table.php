<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demandes d'abonnement des commerçants.
 *
 * Les règlements se font hors application — espèces, Orange Money, virement — et
 * un commerçant ne peut pas s'accorder un plan payant lui-même : il le faisait,
 * en déclarant un paiement « en espèces » qui activait le plan sur-le-champ.
 *
 * Il dépose désormais une demande ici. L'exploitant l'approuve une fois l'argent
 * constaté, et c'est cette approbation qui ouvre l'accès.
 *
 * Le montant annoncé est figé au dépôt : un tarif qui change entre-temps ne doit
 * pas transformer ce qui a été demandé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('plan', 20);
            // Moyen annoncé par le commerçant. Indicatif : c'est l'exploitant qui
            // constate l'argent, quel que soit le canal emprunté.
            $table->string('method', 30)->nullable();
            $table->decimal('amount_due', 14, 2)->default(0);
            $table->string('currency', 3)->default('XOF');
            $table->unsignedSmallInteger('months')->default(1);

            // Mot du commerçant, et numéro à rappeler : celui du business ne
            // convient pas toujours pour joindre quelqu'un.
            $table->string('note', 500)->nullable();
            $table->string('contact_phone', 30)->nullable();

            // Preuve de paiement, facultative : une photo du reçu, une capture du
            // SMS de confirmation, ou le texte du SMS recopié. Facultative parce
            // qu'un commerçant qui paie de la main à la main n'en a pas.
            $table->string('proof_path')->nullable();
            $table->text('proof_note')->nullable();

            $table->string('status', 20)->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('decision_note', 500)->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_requests');
    }
};
