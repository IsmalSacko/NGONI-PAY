<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Journée d'affaires et clôture (ticket Z), comme sur une caisse de
 * restaurant : chaque vente appartient à une journée et y reçoit un numéro du
 * jour (1, 2, 3… qui repart à 1 après la clôture). La clôture fige les
 * chiffres de la journée ; une vente faite après compte pour la suivante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->date('jour_affaire')->nullable()->index();
            $table->unsignedInteger('numero_jour')->nullable();
        });

        Schema::create('clotures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id')->index();
            $table->unsignedInteger('numero');
            $table->date('jour_affaire');
            $table->uuid('user_id');
            $table->json('totaux');
            $table->timestamps();
            $table->unique(['boutique_id', 'jour_affaire']);
            $table->unique(['boutique_id', 'numero']);
            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
        });

        // Ventes existantes : journée = date de la vente, numéro du jour dans l'ordre.
        DB::table('ventes')->orderBy('boutique_id')->orderBy('created_at')->orderBy('numero')
            ->select('id', 'boutique_id', 'created_at')->chunk(500, function ($ventes): void {
                static $compteurs = [];
                foreach ($ventes as $v) {
                    $jour = substr((string) $v->created_at, 0, 10);
                    $cle = $v->boutique_id.'|'.$jour;
                    $compteurs[$cle] = ($compteurs[$cle] ?? 0) + 1;
                    DB::table('ventes')->where('id', $v->id)->update(['jour_affaire' => $jour, 'numero_jour' => $compteurs[$cle]]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('clotures');
        Schema::table('ventes', fn (Blueprint $t) => $t->dropColumn(['jour_affaire', 'numero_jour']));
    }
};
