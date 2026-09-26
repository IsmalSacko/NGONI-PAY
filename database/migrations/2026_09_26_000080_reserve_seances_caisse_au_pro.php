<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Séances de caisse et suivi des écarts réservés au Pro, et descriptions qui
 * disent exactement ce que chaque plan contient.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')->where('code', 'basic')->update([
            'fonctionnalites' => json_encode([]),
            'description' => 'Une boutique et jusqu’à 3 membres : caisse, montant libre, tickets et reçus, catalogue et stocks, clients, historique et tableau de bord.',
        ]);

        DB::table('plans')->where('code', 'pro')->update([
            'fonctionnalites' => json_encode(['seances_caisse']),
            'description' => 'Tout le Basic, plus : jusqu’à 5 boutiques, équipe illimitée, séances de caisse et suivi des écarts.',
        ]);
    }

    public function down(): void
    {
        DB::table('plans')->whereIn('code', ['basic', 'pro'])->update(['fonctionnalites' => json_encode([])]);
    }
};
