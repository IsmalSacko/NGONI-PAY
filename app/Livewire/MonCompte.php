<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Boutique;
use App\Services\MonCompte as Compte;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Son propre compte, depuis le web : le commerçant dans son back-office,
 * l'exploitant dans sa console. Mêmes règles que l'application (MonCompte).
 */
class MonCompte extends Component
{
    public string $name = '';

    public string $telephone = '';

    public string $email = '';

    public string $mot_de_passe_actuel = '';

    public string $mot_de_passe = '';

    public string $mot_de_passe_confirmation = '';

    public ?string $statutProfil = null;

    public ?string $statutMotDePasse = null;

    /** Bilan du soir en notification : pour le titulaire d'une boutique, à qui il part. */
    public bool $bilanQuotidien = true;

    public bool $titulaire = false;

    public function mount(): void
    {
        $user = Auth::user();
        $this->name = (string) $user->name;
        $this->telephone = (string) $user->phone;
        $this->email = (string) $user->email;
        $this->bilanQuotidien = (bool) $user->bilan_quotidien;
        $this->titulaire = Boutique::where('proprietaire_id', $user->id)->exists();
    }

    /** Enregistré dès que la case change, comme dans l'application. */
    public function updatedBilanQuotidien(bool $actif): void
    {
        Auth::user()->forceFill(['bilan_quotidien' => $actif])->save();
    }

    public function enregistrerProfil(Compte $compte): void
    {
        // La validation se fait dans le service : les erreurs d'un essai
        // précédent ne s'effacent pas d'elles-mêmes.
        $this->resetErrorBag(['name', 'telephone', 'email']);
        $this->statutProfil = null;
        $user = $compte->modifierProfil(Auth::user(), $this->only(['name', 'telephone', 'email']));
        $this->telephone = (string) $user->phone;
        $this->statutProfil = 'Profil mis à jour.';
    }

    public function changerMotDePasse(Compte $compte): void
    {
        $this->resetErrorBag(['mot_de_passe_actuel', 'mot_de_passe']);
        $this->statutMotDePasse = null;
        $compte->changerMotDePasse(Auth::user(), $this->only(['mot_de_passe_actuel', 'mot_de_passe', 'mot_de_passe_confirmation']));
        // Nouvelle session : l'ancien identifiant, s'il a fuité avec le mot de passe, ne sert plus.
        session()->regenerate();
        $this->reset(['mot_de_passe_actuel', 'mot_de_passe', 'mot_de_passe_confirmation']);
        $this->statutMotDePasse = 'Mot de passe modifié. Vos appareils connectés devront se reconnecter.';
    }

    public function render()
    {
        return view('livewire.mon-compte')
            ->layout(Auth::user()->est_admin_plateforme ? 'layouts.plateforme' : 'layouts.app', ['title' => 'Mon compte'])
            ->title('Mon compte');
    }
}
