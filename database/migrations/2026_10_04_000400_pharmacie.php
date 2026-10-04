<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode pharmacie, choisi dans les réglages de la boutique (« commerce » par
 * défaut : rien ne change pour les autres).
 *
 * - le médicament : sa DCI (molécule), « sur ordonnance », et ses paliers de
 *   détail (1 boîte = 2 plaquettes = 16 comprimés, un prix par palier) — le
 *   stock se compte alors dans l'unité la plus petite ;
 * - les lots, avec leur date de péremption, vendus le plus proche d'abord ;
 * - l'ordonnance, gardée sur la vente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $t): void {
            $t->string('activite', 20)->default('commerce');
        });

        Schema::table('produits', function (Blueprint $t): void {
            $t->string('dci', 120)->nullable()->after('format');
            $t->boolean('sur_ordonnance')->default(false)->after('dci');
            // [{unite, contenance, prix}] du plus petit au plus grand palier.
            $t->json('paliers')->nullable()->after('unite');
        });

        Schema::table('lignes_vente', function (Blueprint $t): void {
            // Combien d'unités de base fait une unité de la ligne (16 pour une
            // boîte de 16 comprimés) ; 1 sans détail.
            $t->unsignedInteger('contenance')->default(1)->after('unite');
            // Lots entamés : [{lot_id, quantite}], rendus si la vente est annulée.
            $t->json('lots')->nullable()->after('contenance');
        });

        Schema::table('lignes_achat', function (Blueprint $t): void {
            $t->string('unite', 10)->nullable()->after('quantite');
            $t->unsignedInteger('contenance')->default(1)->after('unite');
            $t->string('numero_lot', 60)->nullable()->after('contenance');
            $t->date('peremption')->nullable()->after('numero_lot');
        });

        Schema::table('ventes', function (Blueprint $t): void {
            // {prescripteur, numero, patient}
            $t->json('ordonnance')->nullable();
        });

        Schema::create('lots', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->foreignUuid('produit_id')->constrained('produits')->cascadeOnDelete();
            $t->string('numero', 60)->nullable();
            $t->date('peremption')->nullable();
            // En unités de base (comprimés).
            $t->decimal('quantite_initiale', 14, 3);
            $t->decimal('quantite', 14, 3);
            $t->foreignUuid('achat_id')->nullable()->constrained('achats')->nullOnDelete();
            $t->timestamps();
            $t->index(['produit_id', 'peremption']);
            $t->index(['boutique_id', 'peremption']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lots');
        Schema::table('ventes', fn (Blueprint $t) => $t->dropColumn('ordonnance'));
        Schema::table('lignes_achat', fn (Blueprint $t) => $t->dropColumn(['unite', 'contenance', 'numero_lot', 'peremption']));
        Schema::table('lignes_vente', fn (Blueprint $t) => $t->dropColumn(['contenance', 'lots']));
        Schema::table('produits', fn (Blueprint $t) => $t->dropColumn(['dci', 'sur_ordonnance', 'paliers']));
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn('activite'));
    }
};
