<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Achats et fournisseurs, fidélité, droits par membre, factures et exports,
 * objectif du mois : des fonctions du plan Pro (Plan::FONCTIONNALITES).
 * Ajoutées à ce que le Pro inclut, sans rien retirer ; ensuite, la console
 * les coche ou décoche plan par plan.
 */
return new class extends Migration
{
    private const FONCTIONS = ['achats_fournisseurs', 'fidelite', 'droits_membres', 'factures_exports', 'objectif_mois'];

    public function up(): void
    {
        foreach (DB::table('plans')->where('code', 'pro')->get(['id', 'fonctionnalites']) as $plan) {
            $fonctions = json_decode((string) $plan->fonctionnalites, true) ?: [];
            DB::table('plans')->where('id', $plan->id)->update([
                'fonctionnalites' => json_encode(array_values(array_unique([...$fonctions, ...self::FONCTIONS]))),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->where('code', 'pro')->get(['id', 'fonctionnalites']) as $plan) {
            $fonctions = array_values(array_diff(json_decode((string) $plan->fonctionnalites, true) ?: [], self::FONCTIONS));
            DB::table('plans')->where('id', $plan->id)->update(['fonctionnalites' => json_encode($fonctions)]);
        }
    }
};
