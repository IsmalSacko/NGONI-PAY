<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le grossiste accepte ses revendeurs : son catalogue (articles, prix) ne se
 * montre qu'à eux. Une boutique peut aussi refuser toute commande.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liaisons_grossistes', function (Blueprint $table) {
            $table->id();
            $table->uuid('boutique_revendeur_id');
            $table->uuid('boutique_grossiste_id')->index();
            // demande → acceptee, ou refusee.
            $table->string('statut', 10)->default('demande');
            $table->timestamps();
            $table->unique(['boutique_revendeur_id', 'boutique_grossiste_id'], 'liaison_unique');
        });
        Schema::table('boutiques', function (Blueprint $table) {
            $table->boolean('recoit_commandes')->default(true)->after('mode_vente');
        });

        // Ceux qui ont déjà travaillé ensemble (commande confirmée ou livrée) sont acceptés.
        DB::table('commandes_fournisseur')->whereNotNull('boutique_grossiste_id')
            ->whereIn('statut', ['confirmee', 'en_livraison', 'livree'])
            ->select('boutique_id', 'boutique_grossiste_id')->distinct()->get()
            ->each(fn ($c) => DB::table('liaisons_grossistes')->insertOrIgnore([
                'boutique_revendeur_id' => $c->boutique_id, 'boutique_grossiste_id' => $c->boutique_grossiste_id,
                'statut' => 'acceptee', 'created_at' => now(), 'updated_at' => now(),
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('liaisons_grossistes');
        Schema::table('boutiques', function (Blueprint $table) {
            $table->dropColumn('recoit_commandes');
        });
    }
};
