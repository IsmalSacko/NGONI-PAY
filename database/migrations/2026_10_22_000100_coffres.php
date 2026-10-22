<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le coffre : une partie de la recette mise de côté, à la main ou chaque soir
 * automatiquement (une somme par jour, ou une part des ventes encaissées).
 * Ngoni ne déplace pas l'argent : il compte ce que le commerçant a mis de côté.
 * Une fonction du plan Pro (Plan::COFFRE), ajoutée à ce que le Pro inclut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coffres', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->string('nom', 40);
            // manuel : seulement à la main ; fixe : une somme chaque jour ;
            // pourcentage : une part de ce qui a été encaissé dans la journée.
            $t->string('mode', 20)->default('manuel');
            $t->unsignedBigInteger('montant_fixe')->nullable();
            $t->decimal('pourcentage', 5, 2)->nullable();
            // La journée depuis laquelle la règle s'applique : le versement du
            // soir ne remonte jamais avant (un coffre passé en automatique le
            // matin ne prélève pas la veille au titre du rattrapage).
            $t->date('regle_depuis')->nullable();
            $t->timestamps();
            $t->index(['boutique_id', 'created_at']);
        });

        Schema::create('mouvements_coffre', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->foreignUuid('coffre_id')->constrained('coffres')->cascadeOnDelete();
            $t->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            // ajout et retrait : à la main ; automatique : le versement du soir.
            $t->string('type', 20);
            $t->unsignedBigInteger('montant');
            // La journée de vente concernée, comme les ventes (jour_affaire).
            $t->date('jour');
            // Versement automatique : l'encaissé du jour sur lequel il est calculé.
            $t->unsignedBigInteger('base')->nullable();
            // Un seul versement automatique par coffre et par jour, même si la
            // tâche du soir repasse : NULL pour les mouvements à la main.
            $t->date('jour_automatique')->nullable();
            $t->timestamps();
            $t->index(['boutique_id', 'jour']);
            $t->unique(['coffre_id', 'jour_automatique']);
        });

        foreach (DB::table('plans')->where('code', 'pro')->get(['id', 'fonctionnalites']) as $plan) {
            $fonctions = json_decode((string) $plan->fonctionnalites, true) ?: [];
            DB::table('plans')->where('id', $plan->id)->update([
                'fonctionnalites' => json_encode(array_values(array_unique([...$fonctions, 'coffre']))),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->get(['id', 'fonctionnalites']) as $plan) {
            $fonctions = array_values(array_diff(json_decode((string) $plan->fonctionnalites, true) ?: [], ['coffre']));
            DB::table('plans')->where('id', $plan->id)->update(['fonctionnalites' => json_encode($fonctions)]);
        }
        Schema::dropIfExists('mouvements_coffre');
        Schema::dropIfExists('coffres');
    }
};
