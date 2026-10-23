<?php

declare(strict_types=1);

namespace App\Livewire\Depenses;

use App\Enums\MoyenPaiement;
use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Boutique;
use App\Models\Depense;
use App\Models\Plan;
use App\Services\AbonnementService;
use App\Services\Depenses;
use App\Support\Money\Montant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dépenses de la boutique et bilan, comme l'écran Dépenses et la carte Bilan de
 * l'application : ce qui est entré, ce qui est sorti, ce qui reste.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique;

    /** Le mois affiché, « AAAA-MM ». */
    public string $mois = '';

    public bool $formulaire = false;

    public string $libelle = '';

    public string $categorie = 'loyer';

    public string $montant = '';

    public string $moyen = 'especes';

    public string $jour = '';

    public ?string $info = null;

    public function mount(): void
    {
        $this->mois = now()->format('Y-m');
    }

    /** Les actions Livewire ne repassent pas par les middlewares de la route. */
    private function autoriser(string $permission): void
    {
        Auth::user()->can($permission) || abort(403);
        app(AbonnementService::class)->permet(Boutique::find($this->boutiqueActiveId()), Plan::DEPENSES) || abort(403);
    }

    public function moisPrecedent(): void
    {
        $this->mois = $this->debut()->subMonth()->format('Y-m');
    }

    public function moisSuivant(): void
    {
        // Pas de mois à venir : rien n'y est encore dépensé.
        if ($this->debut()->addMonth()->lte(now())) {
            $this->mois = $this->debut()->addMonth()->format('Y-m');
        }
    }

    public function nouvelle(): void
    {
        $this->autoriser('ventes.create');
        $this->resetValidation();
        $this->reset(['libelle', 'montant', 'info']);
        $this->categorie = array_key_first(Depense::CATEGORIES);
        $this->moyen = MoyenPaiement::Especes->value;
        $this->jour = now()->toDateString();
        $this->formulaire = true;
    }

    public function enregistrer(Depenses $depenses): void
    {
        $this->autoriser('ventes.create');
        if (! $this->abonnementActif()) {
            return;
        }
        $this->montant = (string) (Montant::parse($this->montant) ?? '');
        $data = $this->validate([
            'libelle' => ['required', 'string', 'max:120'],
            'categorie' => ['required', Rule::in(array_keys(Depense::CATEGORIES))],
            'montant' => ['required', 'integer', 'min:1'],
            'moyen' => ['required', Rule::enum(MoyenPaiement::class)],
            // Une dépense déjà payée : aujourd'hui ou avant, jamais à venir.
            'jour' => ['required', 'date', 'before_or_equal:today'],
        ], [], ['libelle' => 'quoi', 'montant' => 'montant', 'jour' => 'jour']);

        $d = $depenses->depenser([
            'libelle' => $data['libelle'], 'categorie' => $data['categorie'], 'montant' => (int) $data['montant'],
            'moyen_paiement' => $data['moyen'], 'jour' => $data['jour'],
        ], Auth::user());

        $this->formulaire = false;
        $this->mois = $d->jour->format('Y-m');
        $this->info = 'Dépense enregistrée : '.$d->libelle.', '.Montant::format($d->montant).'.';
    }

    public function supprimer(string $id): void
    {
        $this->autoriser('ventes.delete');
        Depense::findOrFail($id)->delete();
        $this->info = 'Dépense supprimée.';
    }

    private function debut(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', ($this->mois ?: now()->format('Y-m')).'-01')->startOfDay();
    }

    public function render(Depenses $depenses)
    {
        $du = $this->debut();
        $fin = $du->copy()->endOfMonth();
        // Le mois en cours s'arrête à aujourd'hui, comme la carte Bilan.
        $au = $fin->isFuture() ? now() : $fin;

        return view('livewire.depenses.index', [
            'depenses' => Depense::with('auteur:id,name')
                ->whereDate('jour', '>=', $du->toDateString())->whereDate('jour', '<=', $fin->toDateString())
                ->orderByDesc('jour')->orderByDesc('created_at')->get(),
            'bilan' => $depenses->bilan($du, $au),
            'categories' => Depense::CATEGORIES,
            'moyens' => collect([MoyenPaiement::Especes, MoyenPaiement::OrangeMoney, MoyenPaiement::Wave, MoyenPaiement::MoovMoney, MoyenPaiement::Carte, MoyenPaiement::Virement])
                ->mapWithKeys(fn (MoyenPaiement $m) => [$m->value => $m->label()])->all(),
            'titreMois' => ucfirst($du->locale('fr')->translatedFormat('F Y')),
            'dernierMois' => $du->copy()->addMonth()->isFuture(),
        ]);
    }
}
