<?php

declare(strict_types=1);

namespace App\Livewire\Ventes;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Vente;
use App\Services\VenteService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique, WithPagination;

    public ?string $venteOuverte = null;

    public string $motif = '';

    public function voir(string $venteId): void
    {
        $this->venteOuverte = $venteId;
        $this->reset('motif');
        $this->resetValidation();
    }

    /** Annulation tracée : motif obligatoire, stock remis (voir VenteService). */
    public function annuler(VenteService $ventes): void
    {
        Auth::user()->can('ventes.delete') || abort(403);
        $this->validate(['motif' => ['required', 'string', 'max:255']], [], ['motif' => 'motif']);

        try {
            $ventes->annuler(Vente::findOrFail($this->venteOuverte), Auth::user(), $this->motif);
        } catch (ValidationException $e) {
            $this->addError('motif', collect($e->errors())->flatten()->first());

            return;
        }

        $this->reset('motif');
    }

    public function fermer(): void
    {
        $this->venteOuverte = null;
    }

    public function render()
    {
        // Comme l'API : sans view_all, ses propres ventes seulement.
        $visibles = fn () => Vente::query()->when(! Auth::user()->can('ventes.view_all'), fn ($q) => $q->where('user_id', Auth::id()));
        $ventes = $visibles()->with('caissier', 'client')->latest()->paginate(20);

        $detail = $this->venteOuverte
            ? $visibles()->with('lignes', 'caissier', 'client')->find($this->venteOuverte)?->setAttribute('annule_par_nom',
                \App\Models\User::whereKey(Vente::whereKey($this->venteOuverte)->value('annulee_par'))->value('name'))
            : null;

        return view('livewire.ventes.index', ['ventes' => $ventes, 'detail' => $detail]);
    }
}
