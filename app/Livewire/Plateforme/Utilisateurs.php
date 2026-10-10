<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Models\User;
use App\Services\ComptesPlateforme;
use App\Services\ConditionsUtilisation;
use App\Services\Console\ComptesConsole;
use App\Services\Console\UtilisateursConsole;
use App\Services\SuppressionCompte;
use App\Support\WhatsApp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tous les comptes : activer, désactiver, et mot de passe provisoire pour un
 * commerçant sans e-mail qui a perdu le sien (transmis sur WhatsApp).
 */
#[Layout('layouts.plateforme', ['title' => 'Utilisateurs'])]
class Utilisateurs extends Component
{
    use WithPagination;

    public string $recherche = '';

    /** Voir UtilisateursConsole::TRIS. */
    public string $tri = 'recents';

    /** Voir UtilisateursConsole::ROLES, ETATS, APPLIS. */
    public string $role = '';

    public string $etat = '';

    public string $pays = '';

    public string $appli = '';

    /** Voir UtilisateursConsole::CONDITIONS. */
    public string $conditionsFiltre = '';

    public ?string $motDePasseProvisoire = null;

    public ?string $pour = null;

    public ?string $lienWhatsApp = null;

    /** Compte dont la suppression est en cours de confirmation, et son aperçu. */
    public ?string $aSupprimer = null;

    public array $apercu = [];

    public string $confirmation = '';

    public ?string $info = null;

    public ?string $alerte = null;

    public function updating(string $propriete): void
    {
        if (in_array($propriete, ['recherche', 'tri', 'role', 'etat', 'pays', 'appli', 'conditionsFiltre'], true)) {
            $this->resetPage();
        }
    }

    public function basculer(string $userId, ComptesPlateforme $comptes): void
    {
        $this->alerte = null;
        try {
            $comptes->basculer(User::findOrFail($userId), Auth::user());
        } catch (ValidationException $e) {
            $this->alerte = collect($e->errors())->flatten()->first();
        }
    }

    public function motDePasse(string $userId, ComptesPlateforme $comptes): void
    {
        $this->alerte = null;
        $user = User::findOrFail($userId);

        try {
            $provisoire = $comptes->motDePasseProvisoire($user, Auth::user());
        } catch (ValidationException $e) {
            $this->alerte = collect($e->errors())->flatten()->first();

            return;
        }

        $this->motDePasseProvisoire = $provisoire['mot_de_passe'];
        $this->pour = "{$user->name} · {$user->phone}";
        $this->lienWhatsApp = $provisoire['whatsapp'];
    }

    /** Ouvre la confirmation : ce qui partira, avant d'effacer quoi que ce soit. */
    public function preparerSuppression(string $userId, SuppressionCompte $suppression): void
    {
        $this->reset(['alerte', 'info', 'confirmation']);
        $this->aSupprimer = $userId;
        $this->apercu = $suppression->apercu(User::findOrFail($userId));
    }

    public function annulerSuppression(): void
    {
        $this->reset(['aSupprimer', 'apercu', 'confirmation']);
    }

    public function supprimer(SuppressionCompte $suppression): void
    {
        $this->validate(['confirmation' => ['required', 'in:SUPPRIMER']], ['confirmation.in' => 'Tapez SUPPRIMER pour confirmer.', 'confirmation.required' => 'Tapez SUPPRIMER pour confirmer.']);
        $user = User::findOrFail($this->aSupprimer);

        try {
            $r = $suppression->supprimer($user, Auth::user());
        } catch (ValidationException $e) {
            $this->alerte = collect($e->errors())->flatten()->first();

            return;
        }

        $this->info = "Compte de {$user->name} supprimé, avec {$r['boutiques']} boutique(s) et {$r['comptes']} compte(s). Sauvegarde : {$r['sauvegarde']}";
        $this->annulerSuppression();
    }

    public function fermerMotDePasse(): void
    {
        $this->reset(['motDePasseProvisoire', 'pour', 'lienWhatsApp']);
    }

    public function render()
    {
        // Activité et usage, en sous-requêtes : une seule requête pour la page.
        $users = app(UtilisateursConsole::class)->requete([
            'recherche' => $this->recherche, 'role' => $this->role, 'etat' => $this->etat,
            'pays' => $this->pays, 'appli' => $this->appli, 'conditions' => $this->conditionsFiltre, 'tri' => $this->tri,
        ])
            ->paginate(25);

        return view('livewire.plateforme.utilisateurs', [
            'users' => $users,
            'listePays' => app(ComptesConsole::class)->pays(),
            // Dernière acceptation des conditions de chacun, et la version en vigueur.
            'conditions' => app(ConditionsUtilisation::class)->dernieres($users->getCollection()->pluck('id')->map(fn ($id) => (string) $id)->all()),
            'versionConditions' => ConditionsUtilisation::version(),
        ]);
    }
}
