<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le prix d'achat au moment de la vente : la marge d'une période reste juste
 * même si le prix d'achat de l'article change ensuite. Les lignes existantes
 * reprennent le prix d'achat actuel de l'article (meilleure estimation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lignes_vente', function (Blueprint $table) {
            $table->unsignedInteger('prix_achat')->nullable()->after('prix_unitaire');
        });

        DB::table('lignes_vente')->whereNotNull('produit_id')->orderBy('id')->chunk(500, function ($lignes): void {
            $prix = DB::table('produits')->whereIn('id', $lignes->pluck('produit_id'))->pluck('prix_achat', 'id');
            foreach ($lignes as $l) {
                if (($prix[$l->produit_id] ?? null) !== null) {
                    DB::table('lignes_vente')->where('id', $l->id)->update(['prix_achat' => $prix[$l->produit_id]]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('lignes_vente', fn (Blueprint $t) => $t->dropColumn('prix_achat'));
    }
};
