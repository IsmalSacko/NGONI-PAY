<?php

declare(strict_types=1);

namespace App\Livewire\Boutiques;

use App\Enums\Country;
use App\Models\Boutique;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\BoutiqueActive;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
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
    public bool $modaleOuverte = false;

    public string $nom = '';

    public string $pays = '';

    public string $telephone = '';

    public string $adresse = '';

    public ?string $alerte = null;

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
            'active' => app(\App\Support\Tenancy\TenantContext::class)->boutiqueId(),
            'peutCreer' => $abonnements->peutCreerBoutique($user),
            'listePays' => Country::cases(),
            'maxBoutiques' => $abonnements->planDe($user->abonnement()->first())?->max_boutiques,
            'possedees' => $user->boutiquesPossedees()->count(),
        ]);
    }
}
