<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Models\User;
use App\Support\WhatsApp;
use Illuminate\Support\Facades\Auth;
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

    public ?string $motDePasseProvisoire = null;

    public ?string $pour = null;

    public ?string $lienWhatsApp = null;

    public ?string $alerte = null;

    public function updatingRecherche(): void
    {
        $this->resetPage();
    }

    public function basculer(string $userId): void
    {
        $this->alerte = null;
        $user = User::findOrFail($userId);

        if ($user->id === Auth::id()) {
            $this->alerte = 'Vous ne pouvez pas désactiver votre propre compte.';

            return;
        }

        $user->update(['is_active' => ! $user->is_active]);

        if (! $user->is_active) {
            $user->tokens()->delete();
        }
    }

    public function motDePasse(string $userId): void
    {
        $this->alerte = null;
        $user = User::findOrFail($userId);

        if ($user->est_admin_plateforme && $user->id !== Auth::id()) {
            $this->alerte = 'Compte protégé : réinitialisation impossible depuis la console.';

            return;
        }

        // Lisible et facile à dicter : ni 0/O ni 1/l.
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $motDePasse = '';
        for ($i = 0; $i < 10; $i++) {
            $motDePasse .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $user->forceFill(['password' => $motDePasse])->save();
        $user->tokens()->delete();

        $message = "Bonjour {$user->name}, votre mot de passe e-caisse provisoire est : {$motDePasse}\n"
            .'Connectez-vous puis changez-le.';
        $lien = WhatsApp::link($user->phone);

        $this->motDePasseProvisoire = $motDePasse;
        $this->pour = "{$user->name} · {$user->phone}";
        $this->lienWhatsApp = $lien === null ? null : $lien.'?text='.rawurlencode($message);
    }

    public function fermerMotDePasse(): void
    {
        $this->reset(['motDePasseProvisoire', 'pour', 'lienWhatsApp']);
    }

    public function render()
    {
        $needle = '%'.mb_strtolower($this->recherche).'%';

        $users = User::query()
            ->with('boutique')
            ->when($this->recherche !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(phone) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$needle])))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.plateforme.utilisateurs', ['users' => $users]);
    }
}
