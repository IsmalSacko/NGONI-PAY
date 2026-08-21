<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Plans;

use App\Enums\BillingCycle;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use Livewire\Component;

/**
 * Plans et tarifs, tenus par l'exploitant.
 *
 * Les prix étaient écrits dans le code, à trois endroits : les ajuster demandait
 * un déploiement, et une promotion était impossible. Ils se règlent ici, plan par
 * plan et durée par durée — rien n'impose qu'un trimestre vaille trois fois le
 * mois.
 *
 * Une durée peut aussi être fermée sans être supprimée : le tarif reste en base,
 * et l'application ne la propose plus.
 */
class Index extends Component
{
    /**
     * Montants saisis, indexés par identifiant de tarif.
     *
     * @var array<int, string>
     */
    public array $amounts = [];

    /**
     * Durées ouvertes, indexées par identifiant de tarif.
     *
     * @var array<int, bool>
     */
    public array $actives = [];

    /**
     * Plans ouverts, indexés par identifiant de plan.
     *
     * @var array<int, bool>
     */
    public array $plansActive = [];

    /**
     * Quota mensuel de paiements en ligne, par plan. Vide = sans limite.
     *
     * @var array<int, string>
     */
    public array $quotas = [];

    /**
     * Durée de l'essai en jours, pour le plan gratuit.
     *
     * @var array<int, string>
     */
    public array $trials = [];

    public function mount(): void
    {
        $this->hydrateFromDatabase();
    }

    private function hydrateFromDatabase(): void
    {
        foreach ($this->plans() as $plan) {
            $this->plansActive[$plan->id] = $plan->is_active;
            $this->quotas[$plan->id] = $plan->monthly_online_payments === null
                ? ''
                : (string) $plan->monthly_online_payments;
            $this->trials[$plan->id] = (string) ($plan->trial_days ?? '');

            foreach ($plan->prices as $price) {
                // Sans décimales : les francs CFA ne se subdivisent pas, et un
                // champ affichant « 5000.00 » invite à taper des centimes qui
                // n'existent pas.
                $this->amounts[$price->id] = (string) (int) round((float) $price->amount);
                $this->actives[$price->id] = $price->is_active;
            }
        }
    }

    /**
     * Ouvre une durée qui n'avait pas de tarif pour ce plan.
     */
    public function addCycle(int $planId, string $cycle): void
    {
        $billing = BillingCycle::tryFrom($cycle);
        if ($billing === null) return;

        $plan = SubscriptionPlan::with('prices')->findOrFail($planId);

        if ($plan->prices->firstWhere('cycle', $billing)) return;

        // Le multiple du mois comme point de départ : aucune remise n'est
        // inventée, l'exploitant ajuste ensuite.
        $mensuel = $plan->prices->firstWhere('cycle', BillingCycle::Monthly);
        $depart = $mensuel
            ? (int) round((float) $mensuel->amount) * $billing->months()
            : 0;

        $price = $plan->prices()->create([
            'cycle' => $billing->value,
            'amount' => $depart,
            'currency' => 'XOF',
            'is_active' => true,
        ]);

        $this->amounts[$price->id] = (string) $depart;
        $this->actives[$price->id] = true;

        session()->flash('status', "Durée « {$billing->label()} » ouverte pour {$plan->name}.");
    }

    public function save(): void
    {
        $this->validate([
            'amounts.*' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'quotas.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'trials.*' => ['nullable', 'integer', 'min:0', 'max:365'],
        ], [
            'amounts.*.required' => 'Chaque tarif doit porter un montant.',
            'amounts.*.numeric' => 'Un tarif se saisit en chiffres.',
            'amounts.*.min' => 'Un tarif ne peut pas être négatif.',
        ]);

        foreach ($this->amounts as $id => $montant) {
            $price = SubscriptionPlanPrice::find($id);
            if ($price === null) continue;

            $price->update([
                'amount' => (float) $montant,
                'is_active' => (bool) ($this->actives[$id] ?? true),
            ]);
        }

        foreach ($this->plansActive as $id => $actif) {
            $quota = trim((string) ($this->quotas[$id] ?? ''));
            $essai = trim((string) ($this->trials[$id] ?? ''));

            SubscriptionPlan::whereKey($id)->update([
                'is_active' => (bool) $actif,
                // Vide vaut « sans limite » : c'est ce que `null` signifie.
                'monthly_online_payments' => $quota === '' ? null : (int) $quota,
                'trial_days' => $essai === '' ? null : (int) $essai,
            ]);
        }

        $this->hydrateFromDatabase();

        session()->flash('status', 'Tarifs enregistrés. Ils valent aussitôt pour tous les téléphones.');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, SubscriptionPlan>
     */
    private function plans()
    {
        return SubscriptionPlan::with('prices')->ordered()->get();
    }

    public function render()
    {
        return view('livewire.admin.plans.index', [
            'plans' => $this->plans(),
            'cycles' => BillingCycle::cases(),
        ])->layout('components.layouts.admin', ['title' => 'Plans et tarifs']);
    }
}
