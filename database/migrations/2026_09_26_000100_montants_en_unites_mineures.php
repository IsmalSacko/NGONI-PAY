<?php

declare(strict_types=1);

use App\Support\Money\Currencies;
use App\Support\Money\Reechelonnement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les montants passent en unités mineures : centimes pour les devises qui en
 * ont (EUR, GHS…). Le franc CFA n'a pas de décimales : rien ne change pour
 * lui. Les montants existants des autres boutiques étaient saisis en unités
 * entières ; ils sont multipliés pour garder la même valeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('boutiques')->get(['id', 'devise']) as $boutique) {
            $decimales = Currencies::decimals((string) $boutique->devise);
            if ($decimales > 0) {
                Reechelonnement::appliquer($boutique->id, $decimales);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('boutiques')->get(['id', 'devise']) as $boutique) {
            $decimales = Currencies::decimals((string) $boutique->devise);
            if ($decimales > 0) {
                Reechelonnement::appliquer($boutique->id, -$decimales);
            }
        }
    }
};
