<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CycleFacturation;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Site vitrine de Ngoni Caisse, à la racine du domaine. Un compte connecté
 * va directement à son espace (console ou back-office), sauf en aperçu.
 */
class VitrineController extends Controller
{
    public function __invoke(): View|RedirectResponse
    {
        // Un compte connecté va à son espace, sauf s'il demande l'aperçu
        // (lien « Voir le site » de la console).
        if (($user = auth()->user()) && ! request()->boolean('apercu')) {
            return redirect($user->est_admin_plateforme ? '/plateforme' : '/tableau-de-bord');
        }

        $plans = Plan::with('tarifs')->actifs()->ordonnes()->get();
        $essai = $plans->first(fn (Plan $p) => $p->estEssai());

        return view('vitrine', [
            'essaiJours' => $essai?->joursEssai() ?? 7,
            'plans' => $plans->reject(fn (Plan $p) => $p->estEssai())->map(fn (Plan $p) => [
                'code' => $p->code,
                'nom' => $p->nom,
                'description' => $p->description,
                'max_boutiques' => $p->max_boutiques,
                'max_membres' => $p->max_membres,
                // Chaque fonction cochée ou non dans la console : la vitrine
                // suit les plans sans qu'on la retouche.
                'fonctions' => collect(Plan::FONCTIONNALITES)->map(fn (string $libelle, string $code) => [
                    'libelle' => $libelle,
                    'inclus' => $p->inclut($code),
                ])->values()->all(),
                'mensuel' => $p->tarif(CycleFacturation::Mensuel)?->montant,
                'annuel' => $p->tarif(CycleFacturation::Annuel)?->montant,
            ])->values(),
            'communes' => Plan::COMMUNES,
            'storeUrl' => (string) config('mobile.store_url'),
            'whatsapp' => (string) config('ecaisse.support_whatsapp'),
            'version' => \App\Support\VersionApplication::derniere(),
            'parrainage' => [
                'filleul' => \App\Services\Parrainage::JOURS_ESSAI_FILLEUL,
                'parrain' => \App\Services\Parrainage::JOURS_PARRAIN,
                'plafond' => \App\Services\Parrainage::MAX_PAR_AN,
            ],
        ]);
    }
}
