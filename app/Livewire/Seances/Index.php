<?php

declare(strict_types=1);

namespace App\Livewire\Seances;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Boutique;
use App\Models\Plan;
use App\Models\SessionCaisse;
use App\Services\AbonnementService;
use App\Services\SessionCaisseService;
use App\Support\Money\Montant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Séances de caisse : le fond de départ, ce que le tiroir devrait contenir, ce
 * qui a été compté et l'écart, caissier par caissier. Ouvrir une caisse reste
 * un geste de comptoir (l'application) ; le gérant peut ici fermer une séance
 * oubliée ouverte, comme dans l'application.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique;

    /** Le mois affiché, « AAAA-MM ». */
    public string $mois = '';

    /** Séance à fermer (fenêtre de comptage). */
    public ?string $aFermer = null;

    public string $compte = '';

    public string $notes = '';

    public ?string $info = null;

    /** Fenêtre « Ouvrir ma caisse ». */
    public bool $ouverture = false;

    public string $fond = '';

    public function mount(): void
    {
        $this->mois = now()->format('Y-m');
    }

    private function autoriser(): void
    {
        Auth::user()->can('rapports.view') || abort(403);
        app(AbonnementService::class)->planInclut(Boutique::find($this->boutiqueActiveId()), Plan::SEANCES_CAISSE) || abort(403);
    }

    public function moisPrecedent(): void
    {
        $this->mois = $this->debut()->subMonth()->format('Y-m');
    }

    public function moisSuivant(): void
    {
        if ($this->debut()->addMonth()->lte(now())) {
            $this->mois = $this->debut()->addMonth()->format('Y-m');
        }
    }

    /** Ouvrir sa propre caisse, comme au comptoir : offre qui la comprend, abonnement en cours. */
    private function peutOuvrir(SessionCaisseService $sessions): bool
    {
        return Auth::user()->can('sessions_caisse.create')
            && app(AbonnementService::class)->permet(Boutique::find($this->boutiqueActiveId()), Plan::SEANCES_CAISSE)
            && $sessions->courante(Auth::user()) === null;
    }

    public function ouvrirOuverture(SessionCaisseService $sessions): void
    {
        $this->autoriser();
        $this->peutOuvrir($sessions) || abort(403);
        $this->resetValidation();
        // Le fond laissé dans le tiroir à la dernière fermeture, comme dans l'application.
        $derniere = $sessions->derniereFermeture();
        $this->fond = $derniere?->fond_final === null ? '' : Montant::saisie((int) $derniere->fond_final);
        $this->ouverture = true;
    }

    public function ouvrir(SessionCaisseService $sessions): void
    {
        $this->autoriser();
        $this->peutOuvrir($sessions) || abort(403);
        if (! $this->abonnementActif()) {
            return;
        }
        $montant = Montant::parse($this->fond);
        if ($montant === null || $montant < 0) {
            $this->addError('fond', 'Indiquez l’argent présent dans le tiroir (0 s’il est vide).');

            return;
        }
        try {
            $sessions->ouvrir(Auth::user(), $montant);
        } catch (ValidationException $e) {
            $this->addError('fond', collect($e->errors())->flatten()->first());

            return;
        }
        $this->ouverture = false;
        $this->info = 'Caisse ouverte avec un fond de '.Montant::format($montant).'.';
    }

    public function ouvrirFermeture(string $id, SessionCaisseService $sessions): void
    {
        $this->autoriser();
        $this->peutFermer() || abort(403);
        $s = SessionCaisse::findOrFail($id);
        $this->resetValidation();
        $this->aFermer = $s->id;
        // Pré-rempli avec ce que le tiroir devrait contenir, comme dans l'application.
        $this->compte = Montant::saisie($sessions->fondAttendu($s));
        $this->notes = '';
    }

    public function fermer(SessionCaisseService $sessions): void
    {
        $this->autoriser();
        $this->peutFermer() || abort(403);
        $montant = Montant::parse($this->compte);
        if ($montant === null || $montant < 0) {
            $this->addError('compte', 'Indiquez l’argent compté dans le tiroir.');

            return;
        }
        try {
            $s = $sessions->fermer(SessionCaisse::findOrFail($this->aFermer), $montant, trim($this->notes) ?: null);
        } catch (ValidationException $e) {
            $this->addError('compte', collect($e->errors())->flatten()->first());

            return;
        }
        $this->aFermer = null;
        $e = (int) $s->ecart;
        $this->info = 'Séance fermée. '.($e === 0 ? 'Aucun écart.' : ($e < 0
            ? 'Il manquait '.Montant::format(-$e).'.'
            : Montant::format($e).' en trop.'));
    }

    /** Fermer la séance d'un autre : l'encadrement seulement (règle de l'application). */
    private function peutFermer(): bool
    {
        return Auth::user()->can('sessions_caisse.update') && Auth::user()->hasRole(['admin', 'gerant']);
    }

    private function debut(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', ($this->mois ?: now()->format('Y-m')).'-01')->startOfDay();
    }

    public function render(SessionCaisseService $sessions)
    {
        $this->autoriser();
        $du = $this->debut();
        $ouvertes = SessionCaisse::with('caissier:id,name')->where('statut', 'ouverte')->latest('ouverte_le')->get();
        $fermees = SessionCaisse::with('caissier:id,name')->where('statut', 'fermee')
            ->where('ouverte_le', '>=', $du)->where('ouverte_le', '<', $du->copy()->addMonth())
            ->latest('ouverte_le')->get();

        return view('livewire.seances.index', [
            'ouvertes' => $ouvertes->map(fn (SessionCaisse $s) => ['s' => $s, 'attendu' => $sessions->fondAttendu($s)]),
            'fermees' => $fermees,
            'ecartTotal' => (int) $fermees->sum('ecart'),
            'manques' => $fermees->where('ecart', '<', 0)->count(),
            'peutFermer' => $this->peutFermer(),
            'peutOuvrir' => $this->peutOuvrir($sessions),
            'titreMois' => ucfirst($du->locale('fr')->translatedFormat('F Y')),
            'dernierMois' => $du->copy()->addMonth()->isFuture(),
        ]);
    }
}
