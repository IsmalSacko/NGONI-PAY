<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CycleFacturation;
use App\Models\Plan;
use App\Models\PlanTarif;
use Illuminate\Support\Facades\Validator;

/**
 * Plans et tarifs tenus par l'exploitant, depuis la console web (Livewire) ou
 * l'application (API) : mêmes champs, mêmes règles. Ils valent aussitôt pour
 * toutes les applications ; les demandes déjà déposées gardent leur montant.
 */
class GestionPlans
{
    /**
     * Les plans tels que la console les édite : champs en texte (vide = illimité).
     *
     * @return array{plans: array<int, array<string, mixed>>, tarifs: array<int, array<string, array{montant: string, actif: bool}>>}
     */
    public function etat(): array
    {
        $plans = [];
        $tarifs = [];
        foreach (Plan::with('tarifs')->ordonnes()->get() as $plan) {
            $plans[$plan->id] = [
                'nom' => $plan->nom,
                'description' => (string) $plan->description,
                'jours_essai' => (string) ($plan->jours_essai ?? ''),
                'max_boutiques' => (string) ($plan->max_boutiques ?? ''),
                'max_membres' => (string) ($plan->max_membres ?? ''),
                'est_actif' => $plan->est_actif,
                'essai' => $plan->estEssai(),
                'code' => $plan->code,
                'fonctionnalites' => array_fill_keys($plan->fonctionnalites ?? [], true),
            ];

            if (! $plan->estEssai()) {
                foreach (CycleFacturation::cases() as $cycle) {
                    $tarif = $plan->tarifs->first(fn (PlanTarif $t) => $t->cycle === $cycle);
                    $tarifs[$plan->id][$cycle->value] = [
                        'montant' => (string) ($tarif?->montant ?? ''),
                        'actif' => $tarif?->est_actif ?? false,
                    ];
                }
            }
        }

        return ['plans' => $plans, 'tarifs' => $tarifs];
    }

    /** @return array<string, list<string>> */
    public static function regles(): array
    {
        return [
            'plans.*.nom' => ['required', 'string', 'max:60'],
            'plans.*.description' => ['nullable', 'string', 'max:1000'],
            'plans.*.jours_essai' => ['nullable', 'integer', 'min:1', 'max:365'],
            'plans.*.max_boutiques' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'plans.*.max_membres' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'tarifs.*.*.montant' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $plans
     * @param  array<int, array<string, array{montant: mixed, actif: mixed}>>  $tarifs
     */
    public function enregistrer(array $plans, array $tarifs): void
    {
        Validator::make(['plans' => $plans, 'tarifs' => $tarifs], self::regles(), [], ['tarifs.*.*.montant' => 'montant'])->validate();

        $existants = Plan::whereIn('id', array_keys($plans))->get()->keyBy('id');
        foreach ($plans as $id => $p) {
            $plan = $existants->get($id);
            if ($plan === null) {
                continue;
            }
            // L'essai se lit en base, jamais dans ce qui est envoyé.
            $essai = $plan->estEssai();
            $vide = fn ($v) => $v === '' || $v === null ? null : (int) $v;

            Plan::whereKey($id)->update([
                'nom' => $p['nom'],
                'description' => ($p['description'] ?? null) ?: null,
                'jours_essai' => $essai ? ($vide($p['jours_essai'] ?? null) ?? 7) : null,
                'max_boutiques' => $vide($p['max_boutiques'] ?? null),
                'max_membres' => $vide($p['max_membres'] ?? null),
                // L'essai couvre tout, sans case à cocher.
                // update() par la requête ne passe pas par le cast : JSON à la main.
                'fonctionnalites' => json_encode($essai ? [] : array_keys(array_filter(
                    array_intersect_key($p['fonctionnalites'] ?? [], Plan::FONCTIONNALITES),
                ))),
                // L'essai reste toujours disponible : il est offert à l'inscription.
                'est_actif' => $essai ? true : (bool) ($p['est_actif'] ?? false),
            ]);

            if ($essai) {
                continue;
            }
            foreach ($tarifs[$id] ?? [] as $cycle => $tarif) {
                if (CycleFacturation::tryFrom((string) $cycle) === null) {
                    continue;
                }
                if (($tarif['montant'] ?? '') === '' || $tarif['montant'] === null) {
                    PlanTarif::where('plan_id', $id)->where('cycle', $cycle)->update(['est_actif' => false]);

                    continue;
                }

                PlanTarif::updateOrCreate(
                    ['plan_id' => $id, 'cycle' => $cycle],
                    ['montant' => (int) $tarif['montant'], 'devise' => 'XOF', 'est_actif' => (bool) ($tarif['actif'] ?? false)],
                );
            }
        }
    }
}
