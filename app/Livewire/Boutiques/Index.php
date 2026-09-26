<?php

declare(strict_types=1);

namespace App\Livewire\Boutiques;

use App\Enums\Country;
use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Boutique;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Services\ReglagesBoutique;
use App\Support\Money\Currencies;
use App\Support\Tenancy\BoutiqueActive;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Boutiques du compte, et l'ouverture d'une nouvelle — dans la limite du plan
 * (1 boutique en Basic, 5 en Pro). La nouvelle boutique devient la boutique
 * de travail, avec son propriétaire comme admin.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique;

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

    public function mount(): void
    {
        $this->pays = Boutique::find(Auth::user()->boutique_id)?->pays ?? Country::default()->value;
    }

    public function nouvelle(): void
    {
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
            'listePays' => Country::cases(),
            'maxBoutiques' => $abonnements->planDe($user->abonnement()->first())?->max_boutiques,
            'possedees' => $user->boutiquesPossedees()->count(),
            'peutRegler' => $user->can('boutique.update'),
            'devises' => Currencies::codes(),
        ]);
    }
}
