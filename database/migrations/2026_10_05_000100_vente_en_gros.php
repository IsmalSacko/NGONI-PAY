<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vente en gros, en plus du détail (réglage « Vos ventes » de la boutique) :
 * un prix de gros par article (et par conditionnement), appliqué à partir
 * d'une quantité, pour un client revendeur ou par la bascule de la caisse.
 * La vente garde son tarif, la ligne son prix de détail pour le ticket.
 * Fonction de l'offre Pro (Plan::VENTE_GROS), cochable dans la console.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $t): void {
            // detail | detail_gros | gros
            $t->string('mode_vente', 15)->default('detail');
            $t->boolean('vente_commence_en_gros')->default(false);
        });

        Schema::table('produits', function (Blueprint $t): void {
            $t->unsignedBigInteger('prix_gros')->nullable()->after('prix_vente');
            // En unités de base : dès 12 pièces, la ligne passe au prix de gros.
            $t->decimal('seuil_gros', 14, 3)->nullable()->after('prix_gros');
        });

        Schema::table('clients', function (Blueprint $t): void {
            $t->boolean('revendeur')->default(false);
        });

        Schema::table('ventes', function (Blueprint $t): void {
            $t->string('tarif', 10)->default('detail');
        });

        Schema::table('lignes_vente', function (Blueprint $t): void {
            $t->boolean('prix_gros')->default(false)->after('prix_unitaire');
            $t->unsignedBigInteger('prix_detail')->nullable()->after('prix_gros');
        });

        foreach (DB::table('plans')->where('code', 'pro')->get(['id', 'fonctionnalites']) as $plan) {
            $fonctions = json_decode((string) $plan->fonctionnalites, true) ?: [];
            DB::table('plans')->where('id', $plan->id)->update([
                'fonctionnalites' => json_encode(array_values(array_unique([...$fonctions, 'vente_gros']))),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->get(['id', 'fonctionnalites']) as $plan) {
            $fonctions = array_values(array_diff(json_decode((string) $plan->fonctionnalites, true) ?: [], ['vente_gros']));
            DB::table('plans')->where('id', $plan->id)->update(['fonctionnalites' => json_encode($fonctions)]);
        }
        Schema::table('lignes_vente', fn (Blueprint $t) => $t->dropColumn(['prix_gros', 'prix_detail']));
        Schema::table('ventes', fn (Blueprint $t) => $t->dropColumn('tarif'));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('revendeur'));
        Schema::table('produits', fn (Blueprint $t) => $t->dropColumn(['prix_gros', 'seuil_gros']));
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn(['mode_vente', 'vente_commence_en_gros']));
    }
};
