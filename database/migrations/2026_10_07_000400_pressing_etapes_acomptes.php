<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pressing : l'avancement d'une commande (lavage, séchage, repassage,
 * contrôle) avec son historique, et l'argent hors vente — l'acompte versé au
 * dépôt entre dans la caisse du jour, son remboursement en sort. Au retrait,
 * la vente note l'acompte déjà encaissé pour ne pas le compter deux fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_pressing', function (Blueprint $t): void {
            $t->string('etape', 20)->nullable()->after('statut');
            $t->json('historique')->nullable()->after('etape');
        });

        Schema::create('encaissements_pressing', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->foreignUuid('commande_id')->constrained('commandes_pressing')->cascadeOnDelete();
            $t->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('session_caisse_id')->nullable()->constrained('sessions_caisse')->nullOnDelete();
            $t->string('type', 20); // acompte | remboursement
            $t->unsignedBigInteger('montant');
            $t->string('moyen_paiement', 30);
            $t->timestamps();
            $t->index(['boutique_id', 'created_at']);
        });

        Schema::table('ventes', function (Blueprint $t): void {
            $t->unsignedBigInteger('acompte_deduit')->default(0)->after('montant_paye');
        });

        Schema::table('boutiques', function (Blueprint $t): void {
            $t->text('conditions_depot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn('conditions_depot'));
        Schema::table('ventes', fn (Blueprint $t) => $t->dropColumn('acompte_deduit'));
        Schema::dropIfExists('encaissements_pressing');
        Schema::table('commandes_pressing', fn (Blueprint $t) => $t->dropColumn(['etape', 'historique']));
    }
};
