<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Enums\CycleFacturation;
use App\Models\Plan;
use App\Services\GestionPlans;
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

    public function mount(GestionPlans $gestion): void
    {
        ['plans' => $this->plans, 'tarifs' => $this->tarifs] = $gestion->etat();
    }

    public function enregistrer(GestionPlans $gestion): void
    {
        $this->validate(GestionPlans::regles(), [], ['tarifs.*.*.montant' => 'montant']);
        $gestion->enregistrer($this->plans, $this->tarifs);

        $this->info = 'Plans et tarifs enregistrés. Ils valent aussitôt dans l’application.';
    }

    public function render()
    {
        return view('livewire.plateforme.plans', [
            'cycles' => CycleFacturation::cases(),
            'fonctionnalites' => Plan::FONCTIONNALITES,
        ]);
    }
}
