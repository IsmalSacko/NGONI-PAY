<?php

declare(strict_types=1);

namespace App\Livewire\Boutiques;

use App\Enums\Country;
use App\Livewire\Concerns\EstScopeParBoutique;
use App\Livewire\Concerns\ReinitialiseUneBoutique;
use App\Models\Boutique;
use App\Models\Plan;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Services\Fidelite;
use App\Services\Images;
use App\Services\ReglagesBoutique;
use App\Services\ReinitialisationBoutique;
use App\Support\Money\Currencies;
use App\Support\Tenancy\BoutiqueActive;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Boutiques du compte, et l'ouverture d'une nouvelle — dans la limite du plan
 * (1 boutique en Basic, 5 en Pro). La nouvelle boutique devient la boutique
 * de travail, avec son propriétaire comme admin.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique, ReinitialiseUneBoutique, WithFileUploads;

    /** Programme de fidélité, comme dans l'application : enregistré de lui-même. */
    public bool $fideliteActive = false;

    public string $fideliteSeuil = '10';

    public string $fidelitePct = '10';

    public bool $fideliteEnregistree = false;

    public function mount(): void
    {
        $this->pays = Boutique::find(Auth::user()->boutique_id)?->pays ?? Country::default()->value;
        $b = Boutique::find($this->boutiqueActiveId());
        $this->fideliteActive = (bool) $b?->fidelite_seuil;
        $this->fideliteSeuil = (string) ($b?->fidelite_seuil ?? 10);
        $this->fidelitePct = (string) ($b?->fidelite_remise_pct ?? 10);
    }

    /** Activité : « pharmacie » adapte la caisse et le back-office (mots, détail, lots, ordonnance). */
    public function choisirActivite(string $activite): void
    {
        Auth::user()->can('boutique.update') || abort(403);
        in_array($activite, Boutique::ACTIVITES, true) || abort(422);
        Boutique::findOrFail($this->boutiqueActiveId())->forceFill(['activite' => $activite])->save();
        session()->flash('info', $activite === 'pharmacie' ? 'Mode pharmacie activé : la caisse parle le langage de l’officine.' : 'Mode commerce : la caisse reprend ses réglages habituels.');
    }

    /** Vos ventes : au détail, au détail et en gros (offre Pro), en gros uniquement. */
    public function choisirModeVente(string $mode): void
    {
        Auth::user()->can('boutique.update') || abort(403);
        in_array($mode, Boutique::MODES_VENTE, true) || abort(422);
        $b = Boutique::findOrFail($this->boutiqueActiveId());
        if ($mode === 'detail_gros' && ! app(AbonnementService::class)->permet($b, Plan::VENTE_GROS)) {
            session()->flash('info', 'Vente en gros : fonction de l’offre Pro.');

            return;
        }
        $b->forceFill(['mode_vente' => $mode, 'vente_commence_en_gros' => $mode === 'detail_gros' && $b->vente_commence_en_gros])->save();
    }

    public function basculerCommenceEnGros(): void
    {
        Auth::user()->can('boutique.update') || abort(403);
        $b = Boutique::findOrFail($this->boutiqueActiveId());
        $b->forceFill(['vente_commence_en_gros' => $b->venteEnGros() && ! $b->vente_commence_en_gros])->save();
    }

    public function updatedFideliteActive(): void
    {
        $this->enregistrerFidelite();
    }

    public function updatedFideliteSeuil(): void
    {
        $this->enregistrerFidelite();
    }

    public function updatedFidelitePct(): void
    {
        $this->enregistrerFidelite();
    }

    private function enregistrerFidelite(): void
    {
        Auth::user()->can('boutique.update') || abort(403);
        $this->fideliteEnregistree = false;
        $this->resetErrorBag(['fideliteSeuil', 'fidelitePct']);
        if ($this->fideliteActive && (! ctype_digit(trim($this->fideliteSeuil)) || ! ctype_digit(trim($this->fidelitePct)))) {
            return;
        }
        try {
            app(Fidelite::class)->regler(Boutique::findOrFail($this->boutiqueActiveId()), [
                'seuil' => $this->fideliteActive ? (int) $this->fideliteSeuil : null,
                'remise_pct' => (int) $this->fidelitePct,
            ]);
            $this->fideliteEnregistree = true;
        } catch (ValidationException $e) {
            $this->addError('fideliteSeuil', $e->errors()['seuil'][0] ?? '');
            $this->addError('fidelitePct', $e->errors()['remise_pct'][0] ?? '');
        }
    }

    /** Nouveau logo choisi (fichier temporaire Livewire). */
    public $logo = null;

    public function envoyerLogo(Images $images): void
    {
        Auth::user()->can('boutique.update') || abort(403);
        $this->validate(['logo' => Images::REGLES], [], ['logo' => 'logo']);
        $b = Boutique::findOrFail($this->boutiqueActiveId());
        $b->update(['logo' => $images->enregistrer($this->logo, 'logos', $b->id, $b->logo)]);
        $this->reset('logo');
        session()->flash('info', 'Logo enregistré : il apparaît sur les tickets.');
    }

    public function supprimerLogo(Images $images): void
    {
        Auth::user()->can('boutique.update') || abort(403);
        $b = Boutique::findOrFail($this->boutiqueActiveId());
        $images->supprimer($b->logo);
        $b->update(['logo' => null]);
    }

    public bool $modaleOuverte = false;

    public string $nom = '';

    public string $pays = '';

    public string $telephone = '';

    public string $adresse = '';

    public ?string $alerte = null;

    /** Réglages de la boutique active (admin). */
    public bool $reglagesOuverts = false;

    /** @var array{nom: string, pays: string, devise: string, telephone: string, email: string, adresse: string} */
    public array $reglages = ['nom' => '', 'pays' => '', 'devise' => '', 'telephone' => '', 'email' => '', 'adresse' => '', 'convertir' => true, 'taux' => '',
        'identifiant_fiscal' => '', 'rccm' => '', 'message_ticket' => ''];

    /** Devise avant modification : le taux se lit « 1 nouvelle = x ancienne ». */
    public string $deviseInitiale = '';

    public function nouvelle(): void
    {
        Auth::user()->peutOuvrirBoutique() || abort(403);
        $this->resetValidation();
        $this->reset(['nom', 'telephone', 'adresse', 'alerte']);
        $this->modaleOuverte = true;
    }

    public function ouvrirReglages(): void
    {
        Auth::user()->can('boutique.update') || abort(403);
        $b = Boutique::findOrFail($this->boutiqueActiveId());

        $this->resetValidation();
        $this->reglages = [
            'nom' => $b->nom, 'pays' => (string) $b->pays, 'devise' => (string) $b->devise,
            'telephone' => (string) $b->telephone, 'email' => (string) $b->email, 'adresse' => (string) $b->adresse,
            'convertir' => true, 'taux' => '',
            'identifiant_fiscal' => (string) $b->identifiant_fiscal, 'rccm' => (string) $b->rccm, 'message_ticket' => (string) $b->message_ticket,
        ];
        $this->deviseInitiale = (string) $b->devise;
        $this->reglagesOuverts = true;
    }

    public function enregistrerReglages(ReglagesBoutique $service): void
    {
        Auth::user()->can('boutique.update') || abort(403);
        $b = Boutique::findOrFail($this->boutiqueActiveId());

        try {
            $donnees = array_map(fn ($v) => $v === '' ? null : $v, $this->reglages);
            $donnees['taux'] = $donnees['taux'] === null ? null : str_replace(',', '.', (string) $donnees['taux']);
            $b = $service->mettreAJour($b, $donnees);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $champ => $messages) {
                $this->addError('reglages.'.$champ, $messages[0]);
            }

            return;
        }

        $this->reglagesOuverts = false;
        session()->flash('info', "Réglages de « {$b->nom} » enregistrés ({$b->devise}).");
        $this->redirectRoute('boutiques.index', navigate: false);
    }

    /** La boutique de travail, et seulement par son propriétaire. */
    protected function autoriserReinitialisation(string $boutiqueId): void
    {
        $b = Boutique::find($boutiqueId);
        ($b !== null && $boutiqueId === $this->boutiqueActiveId() && ReinitialisationBoutique::autorise(Auth::user(), $b)) || abort(403);
    }

    protected function apresReinitialisation(string $boutique): void
    {
        session()->flash('info', "« {$boutique} » est remise à zéro : elle est prête pour vos vraies ventes.");
        $this->redirectRoute('boutiques.index', navigate: false);
    }

    public function creer(BoutiqueRegistrationService $service): void
    {
        $this->validate([
            'nom' => ['required', 'string', 'max:255'],
            'pays' => ['required', 'string', 'size:2'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'adresse' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $boutique = $service->ajouterBoutique(Auth::user(), [
                'nom' => $this->nom,
                'pays' => $this->pays,
                'telephone' => $this->telephone ?: null,
                'adresse' => $this->adresse ?: null,
            ]);
        } catch (HttpResponseException $e) {
            // Limite du plan ou abonnement terminé : le message du service.
            $this->alerte = $e->getResponse()->getData(true)['message'] ?? 'Impossible d’ouvrir une boutique.';
            $this->modaleOuverte = false;

            return;
        }

        session([BoutiqueActive::CLE_SESSION => $boutique->id]);
        session()->flash('info', "Boutique « {$boutique->nom} » ouverte. Vous y travaillez maintenant.");

        $this->redirectRoute('boutiques.index', navigate: false);
    }

    public function render(AbonnementService $abonnements)
    {
        $user = Auth::user();
        $boutiques = Boutique::whereIn('id', $user->boutiqueIds())->orderBy('nom')->get();

        return view('livewire.boutiques.index', [
            'boutiques' => $boutiques,
            'active' => $this->boutiqueActiveId(),
            'peutCreer' => $abonnements->peutCreerBoutique($user),
            'peutOuvrir' => $user->peutOuvrirBoutique(),
            'listePays' => Country::cases(),
            'maxBoutiques' => $abonnements->planDe($user->abonnement()->first())?->max_boutiques,
            'possedees' => $user->boutiquesPossedees()->count(),
            'peutRegler' => $user->can('boutique.update'),
            'devises' => Currencies::codes(),
            'boutiqueActive' => $boutiques->firstWhere('id', $this->boutiqueActiveId()),
            // Le programme de fidélité est une fonction de l'offre.
            'fideliteIncluse' => ($b = $boutiques->firstWhere('id', $this->boutiqueActiveId())) !== null
                && $abonnements->permet($b, Plan::FIDELITE),
        ]);
    }
}
