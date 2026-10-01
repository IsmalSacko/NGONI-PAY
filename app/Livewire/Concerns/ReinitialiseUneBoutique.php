<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Boutique;
use App\Services\ReinitialisationBoutique;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Fenêtre « Réinitialiser la boutique », commune à la console de l'exploitant
 * et au back-office du commerçant : l'aperçu de ce qui partira, le choix de
 * garder articles et fournisseurs, REINITIALISER à taper, puis une
 * notification. Vue : livewire/partials/reinitialisation.
 *
 * Le composant dit qui y a droit (autoriserReinitialisation) ; le service,
 * lui, refuse de toute façon quiconque n'est ni propriétaire ni exploitant.
 */
trait ReinitialiseUneBoutique
{
    /** Boutique dont la remise à zéro est en cours de confirmation, et son aperçu. */
    public ?string $aReinitialiser = null;

    public array $apercu = [];

    public bool $garderCatalogue = true;

    public bool $garderFournisseurs = true;

    public string $confirmation = '';

    abstract protected function autoriserReinitialisation(string $boutiqueId): void;

    /** Ouvre la confirmation : ce qui partira, avant d'effacer quoi que ce soit. */
    public function preparerReinitialisation(string $boutiqueId, ReinitialisationBoutique $reinitialisation): void
    {
        $this->autoriserReinitialisation($boutiqueId);
        $this->reset(['confirmation', 'garderCatalogue', 'garderFournisseurs']);
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
        $this->autoriserReinitialisation((string) $this->aReinitialiser);
        $this->validate(['confirmation' => ['required', 'in:REINITIALISER']], [
            'confirmation.in' => 'Tapez REINITIALISER pour confirmer.', 'confirmation.required' => 'Tapez REINITIALISER pour confirmer.',
        ]);

        try {
            $r = $reinitialisation->reinitialiser($this->aReinitialiser, Auth::user(), $this->garderCatalogue, $this->garderFournisseurs);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first();
            $this->addError('confirmation', $message);
            $this->dispatch('toast', type: 'erreur', message: $message);

            return;
        }

        $this->annulerReinitialisation();
        $this->dispatch('toast', type: 'succes', message: "Les données d’essai de {$r['boutique']} sont effacées. Une sauvegarde est gardée 30 jours.");
        $this->apresReinitialisation($r['boutique']);
    }

    /** Ce que le composant fait de plus après coup (message, rechargement…). */
    protected function apresReinitialisation(string $boutique): void {}
}
