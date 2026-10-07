<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Les dépenses et le bilan entrent dans l'offre Pro ; tout le pressing
 * (express, collecte, casiers, forfaits…) passe dans tous les plans : sa case
 * « pressing_avance » quitte les offres.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('plans')->get(['id', 'code', 'fonctionnalites']) as $plan) {
            $fonctions = array_values(array_diff(json_decode((string) $plan->fonctionnalites, true) ?: [], ['pressing_avance']));
            if ($plan->code === 'pro') {
                $fonctions = array_values(array_unique([...$fonctions, 'depenses']));
            }
            DB::table('plans')->where('id', $plan->id)->update(['fonctionnalites' => json_encode($fonctions)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->get(['id', 'code', 'fonctionnalites']) as $plan) {
            $fonctions = array_values(array_diff(json_decode((string) $plan->fonctionnalites, true) ?: [], ['depenses']));
            if ($plan->code === 'pro') {
                $fonctions = array_values(array_unique([...$fonctions, 'pressing_avance']));
            }
            DB::table('plans')->where('id', $plan->id)->update(['fonctionnalites' => json_encode($fonctions)]);
        }
    }
};
