<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le transfert d'argent (cabine Orange Money, Moov Money, Wave…) : les
 * opérateurs de la boutique, chaque opération avec ce qu'elle change au solde
 * électronique (« la puce ») et à l'argent du tiroir, et l'arrêté du soir où
 * l'on compte les deux.
 *
 * Ngoni ne passe aucune opération chez l'opérateur : il note ce que le
 * commerçant a fait sur son téléphone d'agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operateurs_transfert', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->string('nom', 40);
            $t->unsignedSmallInteger('ordre')->default(0);
            $t->timestamps();
            // Un opérateur retiré garde ses opérations passées et leur nom.
            $t->softDeletes();
            $t->index(['boutique_id', 'ordre']);
        });

        Schema::create('arretes_transfert', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->bigInteger('especes_theorique');
            $t->bigInteger('especes_compte');
            // [{operateur_id, nom, theorique, compte}] : figé tel qu'au comptage.
            $t->json('puces');
            $t->timestamps();
            $t->index(['boutique_id', 'created_at']);
        });

        Schema::create('operations_transfert', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            // NULL : un mouvement d'espèces seules (apport, retrait du tiroir).
            $t->foreignUuid('operateur_id')->nullable()->constrained('operateurs_transfert')->nullOnDelete();
            $t->string('operateur_nom', 40)->nullable();
            // depot, retrait, envoi, paiement, credit : pour un client ;
            // approvisionnement, vidage, apport_especes, retrait_especes : les
            // fonds du commerçant ; ajustement : l'écart compté à l'arrêté.
            $t->string('type', 20);
            $t->unsignedBigInteger('montant');
            $t->unsignedBigInteger('commission')->default(0);
            // La commission payée en espèces par le client entre dans le tiroir ;
            // celle versée par l'opérateur, non.
            $t->boolean('commission_especes')->default(false);
            // Approvisionnement ou vidage de la puce : payé avec (ou rendu dans) le tiroir.
            $t->boolean('via_tiroir')->default(true);
            // Ce que l'opération change, signé : calculé à l'enregistrement.
            $t->bigInteger('effet_puce')->default(0);
            $t->bigInteger('effet_especes')->default(0);
            $t->string('client_nom', 80)->nullable();
            $t->string('client_telephone', 30)->nullable();
            // Le numéro crédité (envoi) ou la facture payée (n° d'abonné).
            $t->string('beneficiaire', 80)->nullable();
            // L'identifiant du SMS de l'opérateur.
            $t->string('reference', 60)->nullable();
            $t->string('note', 200)->nullable();
            $t->date('jour');
            $t->foreignUuid('arrete_id')->nullable()->constrained('arretes_transfert')->nullOnDelete();
            $t->timestamp('annulee_le')->nullable();
            $t->foreignUuid('annulee_par')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['boutique_id', 'jour']);
            $t->index(['boutique_id', 'client_telephone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_transfert');
        Schema::dropIfExists('arretes_transfert');
        Schema::dropIfExists('operateurs_transfert');
    }
};
