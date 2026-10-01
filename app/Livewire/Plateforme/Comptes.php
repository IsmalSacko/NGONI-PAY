<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\Plan;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\ReinitialisationBoutique;
use App\Services\RestaurationBoutique;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Comptes propriétaires : leurs boutiques, leur abonnement, et les gestes de
 * l'exploitant (accorder, prolonger, révoquer, réinitialiser une boutique ou
 * la restaurer).
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

    /** Boutique dont la remise à zéro est en cours de confirmation, et son aperçu. */
    public ?string $aReinitialiser = null;

    public array $apercu = [];

    public bool $garderCatalogue = true;

    public bool $garderFournisseurs = true;

    public string $confirmation = '';

    /** Boutique dont on choisit la sauvegarde à restaurer, et la sauvegarde choisie. */
    public ?string $aRestaurer = null;

    public ?string $sauvegarde = null;

    public string $confirmationRestauration = '';

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

    /** Ouvre la confirmation : ce qui partira, avant d'effacer quoi que ce soit. */
    public function preparerReinitialisation(string $boutiqueId, ReinitialisationBoutique $reinitialisation): void
    {
        $this->reset(['info', 'confirmation', 'garderCatalogue', 'garderFournisseurs']);
        $this->resetValidation();
        $this->aReinitialiser = $boutiqueId;
        $this->apercu = $reinitialisation->apercuBoutique(Boutique::withoutGlobalScopes()->findOrFail($boutiqueId));
    }

    public function annulerReinitialisation(): void
    {
        $this->reset(['aReinitialiser', 'apercu', 'confirmation']);
    }

    public function reinitialiser(ReinitialisationBoutique $reinitialisation): void
    {
        $this->validate(['confirmation' => ['required', 'in:REINITIALISER']], [
            'confirmation.in' => 'Tapez REINITIALISER pour confirmer.', 'confirmation.required' => 'Tapez REINITIALISER pour confirmer.',
        ]);

        try {
            $r = $reinitialisation->reinitialiser($this->aReinitialiser, Auth::user(), $this->garderCatalogue, $this->garderFournisseurs);
        } catch (ValidationException $e) {
            $this->addError('confirmation', collect($e->errors())->flatten()->first());

            return;
        }

        $this->annulerReinitialisation();
        $this->info = "{$r['boutique']} est remise à zéro.";
    }

    public function preparerRestauration(string $boutiqueId, RestaurationBoutique $restauration): void
    {
        $this->reset(['info', 'confirmationRestauration']);
        $this->resetValidation();
        $this->aRestaurer = $boutiqueId;
        $this->sauvegarde = $restauration->sauvegardesParBoutique()[$boutiqueId][0] ?? null;
    }

    public function annulerRestauration(): void
    {
        $this->reset(['aRestaurer', 'sauvegarde', 'confirmationRestauration']);
    }

    public function restaurer(RestaurationBoutique $restauration): void
    {
        $this->validate(['confirmationRestauration' => ['required', 'in:RESTAURER'], 'sauvegarde' => ['required', 'string']], [
            'confirmationRestauration.in' => 'Tapez RESTAURER pour confirmer.', 'confirmationRestauration.required' => 'Tapez RESTAURER pour confirmer.',
            'sauvegarde.required' => 'Choisissez une sauvegarde.',
        ]);

        // La plus récente de cette boutique seulement : en restaurer une plus
        // ancienne d'abord remettrait le stock d'une étape intermédiaire.
        if ($this->sauvegarde !== ($restauration->sauvegardesParBoutique()[(string) $this->aRestaurer][0] ?? null)) {
            $this->addError('sauvegarde', 'Sauvegarde introuvable pour cette boutique.');

            return;
        }

        try {
            $r = $restauration->restaurer($this->sauvegarde, Auth::user());
        } catch (ValidationException $e) {
            $this->addError('confirmationRestauration', collect($e->errors())->flatten()->first());

            return;
        }

        $this->annulerRestauration();
        $this->info = "{$r['boutique']} est restaurée ({$r['lignes']} lignes remises en place).";
    }

    public function revoquer(string $userId, AbonnementService $service): void
    {
        $proprietaire = User::findOrFail($userId);
        $service->revoquer($proprietaire);
        $this->info = "Abonnement de {$proprietaire->name} révoqué : ses boutiques passent en lecture seule.";
    }

    public function render(RestaurationBoutique $restauration)
    {
        $abonnements = Abonnement::query()->avecCompte()
            ->with(['proprietaire' => fn ($q) => $q->select('users.*')->addSelect([
                'derniere_app' => DB::table('personal_access_tokens')->selectRaw('MAX(last_used_at)')->whereColumn('tokenable_id', 'users.id'),
            ]), 'proprietaire.parrain:id,name', 'proprietaire.boutiquesPossedees' => fn ($q) => $q
                ->withCount(['ventes as nb_ventes' => fn ($v) => $v->withoutGlobalScopes()->where('statut', 'validee')])
                ->withMax(['ventes as derniere_vente' => fn ($v) => $v->withoutGlobalScopes()->where('statut', 'validee')], 'created_at')
                ->withSum(['ventes as total_30j' => fn ($v) => $v->withoutGlobalScopes()->where('statut', 'validee')->where('created_at', '>=', now()->subDays(30))], 'total')])
            ->when($this->recherche !== '', fn ($q) => $q->whereHas('proprietaire', fn ($u) => $u->recherche($this->recherche)))
            ->when($this->filtre === 'actifs', fn ($q) => $q->where('est_actif', true)->where(fn ($w) => $w->whereNull('fin')->orWhereDate('fin', '>=', today())))
            ->when($this->filtre === 'expires', fn ($q) => $q->where(fn ($w) => $w->where('est_actif', false)->orWhereDate('fin', '<', today())))
            ->latest('updated_at')
            ->paginate(20);

        return view('livewire.plateforme.comptes', [
            'abonnements' => $abonnements,
            'plans' => Plan::ordonnes()->get(),
            'sauvegardes' => $restauration->sauvegardesParBoutique(),
            'aRestaurerResume' => $this->aRestaurer ? ($restauration->sauvegardes($this->aRestaurer)[0] ?? null) : null,
            'aRestaurerNom' => $this->aRestaurer ? Boutique::withoutGlobalScopes()->whereKey($this->aRestaurer)->value('nom') : null,
        ]);
    }
}
