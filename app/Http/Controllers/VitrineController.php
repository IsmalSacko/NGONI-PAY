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
        // Les fonctions les plus partagées entre plans payants d'abord : sur
        // chaque carte, les ✓ se suivent puis viennent les —, et une fonction
        // décochée dans la console descend d'elle-même.
        $payants = $plans->reject(fn (Plan $p) => $p->estEssai());
        $ordre = collect(array_keys(Plan::FONCTIONNALITES))
            ->sortBy([fn ($a, $b) => $payants->filter->inclut($b)->count() <=> $payants->filter->inclut($a)->count()])
            ->values();
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
                'fonctions' => $ordre->map(fn (string $code) => [
                    'libelle' => Plan::FONCTIONNALITES[$code],
                    'inclus' => $p->inclut($code),
                ])->all(),
                'mensuel' => $p->tarif(CycleFacturation::Mensuel)?->montant,
                // Les autres durées actives de la console (trimestre, semestre, an), dans l'ordre.
                'autres' => collect([CycleFacturation::Trimestriel, CycleFacturation::Semestriel, CycleFacturation::Annuel])
                    ->map(fn (CycleFacturation $c) => ['montant' => $p->tarif($c)?->montant, 'unite' => $c->unite()])
                    ->filter(fn (array $t) => $t['montant'] !== null)->values()->all(),
            ])->values(),
            // Abonnement à vie : prix, texte et période tenus dans la console
            // (Plans et tarifs), comme dans les conditions d'utilisation.
            'aVie' => Plan::offreAVie($payants),
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
