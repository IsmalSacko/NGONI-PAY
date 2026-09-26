<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Models\Abonnement;
use App\Models\Plan;
use App\Models\User;
use App\Services\AbonnementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Comptes propriétaires : leurs boutiques, leur abonnement, et les gestes de
 * l'exploitant (accorder, prolonger, révoquer).
 */
#[Layout('layouts.plateforme', ['title' => 'Comptes'])]
class Comptes extends Component
{
    use WithPagination;

    public string $recherche = '';

    /** '' | actifs | expires */
    public string $filtre = '';

    public ?string $compteOuvert = null;

    public string $plan = 'basic';

    public string $fin = '';

    public bool $sansEcheance = false;

    public string $note = '';

    public ?string $info = null;

    public function updatingRecherche(): void
    {
        $this->resetPage();
    }

    public function updatingFiltre(): void
    {
        $this->resetPage();
    }

    public function gerer(string $userId): void
    {
        $abonnement = Abonnement::where('user_id', $userId)->first();
        $this->compteOuvert = $userId;
        $this->plan = $abonnement?->plan ?? 'basic';
        $this->fin = ($abonnement?->fin && $abonnement->estEnCours() ? $abonnement->fin : now()->addMonth())->toDateString();
        $this->sansEcheance = $abonnement !== null && $abonnement->fin === null;
        $this->note = (string) ($abonnement?->note_admin ?? '');
        $this->resetValidation();
    }

    public function accorder(AbonnementService $service): void
    {
        $data = $this->validate([
            'plan' => ['required', 'exists:plans,code'],
            'fin' => ['required_unless:sansEcheance,true', 'nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['fin.after_or_equal' => 'La fin doit être aujourd’hui ou plus tard.']);

        $proprietaire = User::findOrFail($this->compteOuvert);
        $service->accorder(
            $proprietaire,
            $data['plan'],
            $this->sansEcheance ? null : Carbon::parse($data['fin']),
            Auth::user(),
            $data['note'] ?: null,
        );

        $this->info = "Abonnement de {$proprietaire->name} mis à jour.";
        $this->compteOuvert = null;
    }

    public function revoquer(string $userId, AbonnementService $service): void
    {
        $proprietaire = User::findOrFail($userId);
        $service->revoquer($proprietaire);
        $this->info = "Abonnement de {$proprietaire->name} révoqué : ses boutiques passent en lecture seule.";
    }

    public function render()
    {
        $needle = '%'.mb_strtolower($this->recherche).'%';

        $abonnements = Abonnement::query()
            ->with(['proprietaire.boutiquesPossedees'])
            ->when($this->recherche !== '', fn ($q) => $q->whereHas('proprietaire', function ($u) use ($needle) {
                $u->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$needle])
                    ->orWhereHas('boutiquesPossedees', fn ($b) => $b->whereRaw('LOWER(nom) LIKE ?', [$needle]));
            }))
            ->when($this->filtre === 'actifs', fn ($q) => $q->where('est_actif', true)->where(fn ($w) => $w->whereNull('fin')->orWhereDate('fin', '>=', today())))
            ->when($this->filtre === 'expires', fn ($q) => $q->where(fn ($w) => $w->where('est_actif', false)->orWhereDate('fin', '<', today())))
            ->latest('updated_at')
            ->paginate(20);

        return view('livewire.plateforme.comptes', [
            'abonnements' => $abonnements,
            'plans' => Plan::ordonnes()->get(),
        ]);
    }
}
