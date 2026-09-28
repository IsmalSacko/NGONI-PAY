<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques avancées : une fonction du plan Pro, comme les séances de
 * caisse. Ajoutée à ce que le plan inclut déjà, sans rien retirer — une
 * fonction décochée depuis la console reste décochée ailleurs.
 */
return new class extends Migration
{
    private const FONCTION = 'statistiques_avancees';

    public function up(): void
    {
        foreach (DB::table('plans')->where('code', 'pro')->get(['id', 'fonctionnalites', 'description']) as $plan) {
            $fonctions = json_decode((string) $plan->fonctionnalites, true) ?: [];
            if (! in_array(self::FONCTION, $fonctions, true)) {
                $fonctions[] = self::FONCTION;
            }
            DB::table('plans')->where('id', $plan->id)->update([
                'fonctionnalites' => json_encode(array_values($fonctions)),
                'description' => str_contains((string) $plan->description, 'statistiques')
                    ? $plan->description
                    : rtrim((string) $plan->description, '.').', statistiques avancées.',
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->where('code', 'pro')->get(['id', 'fonctionnalites']) as $plan) {
            $fonctions = array_values(array_diff(json_decode((string) $plan->fonctionnalites, true) ?: [], [self::FONCTION]));
            DB::table('plans')->where('id', $plan->id)->update(['fonctionnalites' => json_encode($fonctions)]);
        }
    }
};
