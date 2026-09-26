<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Enums\CycleFacturation;
use App\Models\Plan;
use App\Models\PlanTarif;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Plans et tarifs, tenus par l'exploitant : ils valent aussitôt pour toutes les
 * applications, sans déploiement. Les demandes déjà déposées gardent leur montant.
 */
#[Layout('layouts.plateforme', ['title' => 'Plans et tarifs'])]
class Plans extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $plans = [];

    /** @var array<int, array<string, array{montant: string, actif: bool}>> */
    public array $tarifs = [];

    public ?string $info = null;

    public function mount(): void
    {
        foreach (Plan::with('tarifs')->ordonnes()->get() as $plan) {
            $this->plans[$plan->id] = [
                'nom' => $plan->nom,
                'description' => (string) $plan->description,
                'jours_essai' => (string) ($plan->jours_essai ?? ''),
                'max_boutiques' => (string) ($plan->max_boutiques ?? ''),
                'max_membres' => (string) ($plan->max_membres ?? ''),
                'est_actif' => $plan->est_actif,
                'essai' => $plan->estEssai(),
                'code' => $plan->code,
            ];

            if (! $plan->estEssai()) {
                foreach (CycleFacturation::cases() as $cycle) {
                    $tarif = $plan->tarifs->first(fn (PlanTarif $t) => $t->cycle === $cycle);
                    $this->tarifs[$plan->id][$cycle->value] = [
                        'montant' => (string) ($tarif?->montant ?? ''),
                        'actif' => $tarif?->est_actif ?? false,
                    ];
                }
            }
        }
    }

    public function enregistrer(): void
    {
        $this->validate([
            'plans.*.nom' => ['required', 'string', 'max:60'],
            'plans.*.description' => ['nullable', 'string', 'max:1000'],
            'plans.*.jours_essai' => ['nullable', 'integer', 'min:1', 'max:365'],
            'plans.*.max_boutiques' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'plans.*.max_membres' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'tarifs.*.*.montant' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ], [], ['tarifs.*.*.montant' => 'montant']);

        foreach ($this->plans as $id => $p) {
            $vide = fn ($v) => $v === '' || $v === null ? null : (int) $v;

            Plan::whereKey($id)->update([
                'nom' => $p['nom'],
                'description' => $p['description'] ?: null,
                'jours_essai' => $p['essai'] ? ($vide($p['jours_essai']) ?? 7) : null,
                'max_boutiques' => $vide($p['max_boutiques']),
                'max_membres' => $vide($p['max_membres']),
                // L'essai reste toujours disponible : il est offert à l'inscription.
                'est_actif' => $p['essai'] ? true : (bool) $p['est_actif'],
            ]);

            foreach ($this->tarifs[$id] ?? [] as $cycle => $tarif) {
                if ($tarif['montant'] === '' || $tarif['montant'] === null) {
                    PlanTarif::where('plan_id', $id)->where('cycle', $cycle)->update(['est_actif' => false]);

                    continue;
                }

                PlanTarif::updateOrCreate(
                    ['plan_id' => $id, 'cycle' => $cycle],
                    ['montant' => (int) $tarif['montant'], 'devise' => 'XOF', 'est_actif' => (bool) $tarif['actif']],
                );
            }
        }

        $this->info = 'Plans et tarifs enregistrés. Ils valent aussitôt dans l’application.';
    }

    public function render()
    {
        return view('livewire.plateforme.plans', ['cycles' => CycleFacturation::cases()]);
    }
}
