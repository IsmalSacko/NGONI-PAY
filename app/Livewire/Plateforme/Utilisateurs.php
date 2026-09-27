<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Models\User;
use App\Services\ComptesPlateforme;
use App\Services\SuppressionCompte;
use Illuminate\Validation\ValidationException;
use App\Support\WhatsApp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    /** nom | recents (derniers inscrits d'abord) */
    public string $tri = 'recents';

    public ?string $motDePasseProvisoire = null;

    public ?string $pour = null;

    public ?string $lienWhatsApp = null;

    /** Compte dont la suppression est en cours de confirmation, et son aperçu. */
    public ?string $aSupprimer = null;

    public array $apercu = [];

    public string $confirmation = '';

    public ?string $info = null;

    public ?string $alerte = null;

    public function updatingRecherche(): void
    {
        $this->resetPage();
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
        $needle = '%'.mb_strtolower($this->recherche).'%';

        // Activité et usage, en sous-requêtes : une seule requête pour la page.
        $users = User::query()
            ->select('users.*')
            ->addSelect([
                'derniere_app' => DB::table('personal_access_tokens')->selectRaw('MAX(last_used_at)')->whereColumn('tokenable_id', 'users.id'),
                'derniere_web' => DB::table('sessions')->selectRaw('MAX(last_activity)')->whereColumn('user_id', 'users.id'),
                'nb_ventes' => DB::table('ventes')->selectRaw('COUNT(*)')->whereColumn('user_id', 'users.id')->where('statut', 'validee'),
                'nb_appareils' => DB::table('appareils')->selectRaw('COUNT(*)')->whereColumn('user_id', 'users.id'),
            ])
            ->with('boutique')
            ->when($this->recherche !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(phone) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$needle])))
            ->when($this->tri === 'recents', fn ($q) => $q->latest('users.created_at'), fn ($q) => $q->orderBy('name'))
            ->paginate(25);

        return view('livewire.plateforme.utilisateurs', ['users' => $users]);
    }
}
