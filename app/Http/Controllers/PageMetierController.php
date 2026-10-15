<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CycleFacturation;
use App\Models\Plan;
use App\Support\PagesMetier;
use Illuminate\View\View;

/**
 * Une page de la vitrine par métier, et par pays : voir {@see PagesMetier}.
 */
class PageMetierController extends Controller
{
    public function __invoke(string $metier, ?string $pays = null): View
    {
        $m = PagesMetier::METIERS[$metier] ?? abort(404);
        $p = $pays === null ? null : (PagesMetier::PAYS[$pays] ?? abort(404));

        // Le prix d'entrée, en franc CFA seulement : les plans y sont tarifés.
        $basic = Plan::with('tarifs')->actifs()->ordonnes()->get()->reject(fn (Plan $plan) => $plan->estEssai())->first();
        $mensuel = $basic?->tarif(CycleFacturation::Mensuel)?->montant;
        $essai = Plan::with('tarifs')->actifs()->get()->first(fn (Plan $plan) => $plan->estEssai());

        return view('page-metier', [
            'slug' => $metier,
            'metier' => $m,
            'paysSlug' => $pays,
            'pays' => $p,
            'prixMensuel' => $p === null || in_array($p['devise'], ['XOF', 'XAF'], true) ? $mensuel : null,
            'essaiJours' => $essai?->joursEssai() ?? 7,
            'storeUrl' => (string) config('mobile.store_url'),
        ]);
    }
}
