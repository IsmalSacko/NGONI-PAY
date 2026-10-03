<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vente à crédit et back-office web deviennent des cases de la console
 * (Plan::FONCTIONNALITES). Jusqu'ici dans tous les plans : cochées pour
 * chacun, rien ne change tant que l'exploitant ne les décoche pas.
 */
return new class extends Migration
{
    private const FONCTIONS = ['vente_credit', 'backoffice_web'];

    public function up(): void
    {
        foreach (DB::table('plans')->where('code', '!=', 'essai')->get(['id', 'fonctionnalites']) as $plan) {
            $fonctions = json_decode((string) $plan->fonctionnalites, true) ?: [];
            DB::table('plans')->where('id', $plan->id)->update([
                'fonctionnalites' => json_encode(array_values(array_unique([...self::FONCTIONS, ...$fonctions]))),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->get(['id', 'fonctionnalites']) as $plan) {
            $fonctions = array_values(array_diff(json_decode((string) $plan->fonctionnalites, true) ?: [], self::FONCTIONS));
            DB::table('plans')->where('id', $plan->id)->update(['fonctionnalites' => json_encode($fonctions)]);
        }
    }
};
