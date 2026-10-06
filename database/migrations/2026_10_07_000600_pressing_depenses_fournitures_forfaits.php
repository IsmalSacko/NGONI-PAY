<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pressing (offre Pro) : dépenses (sortent de la caisse du jour), stock des
 * fournitures (lessive, cintres…) et forfaits clients (N pièces payées
 * d'avance, déduites à chaque dépôt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depenses_pressing', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('session_caisse_id')->nullable()->constrained('sessions_caisse')->nullOnDelete();
            $t->string('libelle', 120);
            $t->string('categorie', 40);
            $t->unsignedBigInteger('montant');
            $t->string('moyen_paiement', 30);
            $t->date('jour');
            $t->timestamps();
            $t->index(['boutique_id', 'jour']);
        });

        Schema::create('fournitures_pressing', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->string('nom', 80);
            $t->string('unite', 20)->default('unité');
            $t->decimal('quantite', 12, 2)->default(0);
            $t->decimal('seuil', 12, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('forfaits_pressing', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            $t->foreignUuid('vente_id')->nullable()->constrained('ventes')->nullOnDelete();
            $t->string('libelle', 80);
            $t->unsignedInteger('pieces');
            $t->unsignedInteger('pieces_utilisees')->default(0);
            $t->unsignedBigInteger('prix');
            $t->date('debut');
            $t->date('fin');
            $t->timestamps();
        });

        Schema::table('commandes_pressing', function (Blueprint $t): void {
            $t->foreignUuid('forfait_id')->nullable()->after('client_id')->constrained('forfaits_pressing')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commandes_pressing', fn (Blueprint $t) => $t->dropConstrainedForeignId('forfait_id'));
        Schema::dropIfExists('forfaits_pressing');
        Schema::dropIfExists('fournitures_pressing');
        Schema::dropIfExists('depenses_pressing');
    }
};
