<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Models\User;
use App\Services\ComptesPlateforme;
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
