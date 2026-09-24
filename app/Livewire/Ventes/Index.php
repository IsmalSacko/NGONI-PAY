<?php

declare(strict_types=1);

namespace App\Livewire\Ventes;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Vente;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique, WithPagination;

    public ?string $venteOuverte = null;

    public function voir(string $venteId): void
    {
        $this->venteOuverte = $venteId;
    }

    public function fermer(): void
    {
        $this->venteOuverte = null;
    }

    public function render()
    {
        $ventes = Vente::with('caissier', 'client')->latest()->paginate(20);

        $detail = $this->venteOuverte
            ? Vente::with('lignes', 'caissier', 'client')->find($this->venteOuverte)
            : null;

        return view('livewire.ventes.index', ['ventes' => $ventes, 'detail' => $detail]);
    }
}
