<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Livewire\Concerns\ReinitialiseUneBoutique;
use App\Models\Boutique;
use App\Models\Plan;
use App\Services\Console\ComptesConsole;
use App\Services\RestaurationBoutique;
use App\Support\ConsoleNgoni;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Comptes propriétaires : leurs boutiques, leur abonnement (lecture seule :
 * il se gère dans la Console Ngoni), et les gestes de l'exploitant sur une
 * boutique (la réinitialiser ou la restaurer).
 */
#[Layout('layouts.plateforme', ['title' => 'Comptes'])]
class Comptes extends Component
{
    use ReinitialiseUneBoutique, WithPagination;

    public string $recherche = '';

    /**
     * Voir ComptesConsole::STATUTS. Dans l'adresse, comme l'usage et le tri :
     * les chiffres du tableau de bord ouvrent la liste déjà filtrée.
     */
    #[Url(as: 'statut')]
    public string $filtre = '';

    public string $planFiltre = '';

    public string $pays = '';

    public string $activite = '';

    /** Voir ComptesConsole::USAGES. */
    #[Url]
    public string $usage = '';

    /** Voir ComptesConsole::TRIS. */
    #[Url]
    public string $tri = 'recents';

    public ?string $info = null;

    /** Boutique dont on choisit la sauvegarde à restaurer, et la sauvegarde choisie. */
    public ?string $aRestaurer = null;

    public ?string $sauvegarde = null;

    public string $confirmationRestauration = '';

    public function updatingRecherche(): void
    {
        $this->resetPage();
    }

    public function updating(string $propriete): void
    {
        if (in_array($propriete, ['filtre', 'planFiltre', 'pays', 'activite', 'usage', 'tri'], true)) {
            $this->resetPage();
        }
    }

    /** L'exploitant réinitialise n'importe quelle boutique (route réservée à la console). */
    protected function autoriserReinitialisation(string $boutiqueId): void
    {
        Auth::user()?->est_admin_plateforme || abort(403);
    }

    protected function apresReinitialisation(string $boutique): void
    {
        $this->info = "{$boutique} est remise à zéro.";
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
            $message = collect($e->errors())->flatten()->first();
            $this->addError('confirmationRestauration', $message);
            $this->dispatch('toast', type: 'erreur', message: $message);

            return;
        }

        $this->annulerRestauration();
        $this->info = "{$r['boutique']} est restaurée ({$r['lignes']} lignes remises en place).";
        $this->dispatch('toast', type: 'succes', message: "{$r['boutique']} est restaurée : {$r['lignes']} lignes remises en place.");
    }

    public function render(RestaurationBoutique $restauration)
    {
        $console = app(ComptesConsole::class);
        $abonnements = $console->requete([
            'recherche' => $this->recherche, 'statut' => $this->filtre, 'plan' => $this->planFiltre,
            'pays' => $this->pays, 'activite' => $this->activite, 'usage' => $this->usage, 'tri' => $this->tri,
        ])->with(['proprietaire.parrain:id,name', 'proprietaire.boutiquesPossedees' => fn ($q) => $q
            ->withCount(['ventes as nb_ventes' => fn ($v) => $v->withoutGlobalScopes()->where('statut', 'validee')])
            ->withMax(['ventes as derniere_vente' => fn ($v) => $v->withoutGlobalScopes()->where('statut', 'validee')], 'created_at')
            ->withSum(['ventes as total_30j' => fn ($v) => $v->withoutGlobalScopes()->where('statut', 'validee')->where('created_at', '>=', now()->subDays(30))], 'total')])
            ->paginate(20);

        return view('livewire.plateforme.comptes', [
            'abonnements' => $abonnements,
            'compteurs' => $console->compteurs(),
            'console' => $console,
            'listePays' => $console->pays(),
            'plans' => Plan::ordonnes()->get(),
            'lienConsole' => ConsoleNgoni::demandes(),
            'sauvegardes' => $restauration->sauvegardesParBoutique(),
            'aRestaurerResume' => $this->aRestaurer ? ($restauration->sauvegardes($this->aRestaurer)[0] ?? null) : null,
            'aRestaurerNom' => $this->aRestaurer ? Boutique::withoutGlobalScopes()->whereKey($this->aRestaurer)->value('nom') : null,
        ]);
    }
}
