<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Pressing : l'express et la grille des tarifs entrent dans l'offre Pro
 * (le pressing de base — prestations, prix par habit, caisse — reste dans tous les plans).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('plans')->where('code', 'pro')->get(['id', 'fonctionnalites']) as $plan) {
            $fonctions = json_decode((string) $plan->fonctionnalites, true) ?: [];
            DB::table('plans')->where('id', $plan->id)->update([
                'fonctionnalites' => json_encode(array_values(array_unique([...$fonctions, 'pressing_avance']))),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->get(['id', 'fonctionnalites']) as $plan) {
            $fonctions = array_values(array_diff(json_decode((string) $plan->fonctionnalites, true) ?: [], ['pressing_avance']));
            DB::table('plans')->where('id', $plan->id)->update(['fonctionnalites' => json_encode($fonctions)]);
        }
    }
};
