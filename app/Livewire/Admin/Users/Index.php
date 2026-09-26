<?php

namespace App\Livewire\Admin\Users;

use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    /** Mot de passe provisoire à communiquer, affiché une seule fois. */
    public ?string $temporaryPassword = null;
    public ?string $temporaryPasswordFor = null;
    public ?string $temporaryPasswordWhatsApp = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function toggleActive(int $userId): void
    {
        $user = User::findOrFail($userId);

        if ((int) $user->id === (int) auth()->id()) {
            session()->flash('error', 'Vous ne pouvez pas désactiver votre propre compte.');
            return;
        }

        $desactivation = (bool) $user->is_active;

        $user->update(['is_active' => ! $user->is_active]);

        $notifier = app(\App\Services\Notifier::class);

        if ($desactivation) {
            $notifier->accountDeactivated($user);

            // Les jetons sont révoqués : sans cela le compte resterait ouvert sur
            // son téléphone jusqu'à la prochaine connexion, et la désactivation
            // ne serait pas immédiate.
            $user->tokens()->delete();

            session()->flash(
                'status',
                "Compte désactivé. {$user->name} est déconnecté immédiatement.",
            );

            return;
        }

        $notifier->accountReactivated($user);

        session()->flash('status', "Compte réactivé. {$user->name} peut se reconnecter.");
    }

    /**
     * Mot de passe provisoire, pour un commerçant qui a perdu le sien et n'a pas
     * d'e-mail : l'exploitant le lui transmet (WhatsApp), le commerçant le change
     * ensuite depuis son profil. Toutes ses sessions sont fermées.
     */
    public function resetPassword(int $userId): void
    {
        $user = User::findOrFail($userId);

        if ($user->role === User::ROLE_SYSTEM_ADMIN && (int) $user->id !== (int) auth()->id()) {
            session()->flash('error', 'Impossible de réinitialiser ce compte protégé.');
            return;
        }

        // Lisible et facile à dicter : pas de 0/O ni de 1/l.
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $motDePasse = '';
        for ($i = 0; $i < 8; $i++) {
            $motDePasse .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $user->forceFill(['password' => \Illuminate\Support\Facades\Hash::make($motDePasse)])->save();
        $user->tokens()->delete();

        $message = "Bonjour {$user->name}, votre mot de passe Ngoni Pay provisoire est : {$motDePasse}\n"
            . 'Connectez-vous puis changez-le depuis votre profil.';
        $lien = \App\Support\WhatsApp::link($user->phone);

        $this->temporaryPassword = $motDePasse;
        $this->temporaryPasswordFor = $user->name . ' · ' . $user->phone;
        $this->temporaryPasswordWhatsApp = $lien === null ? null : $lien . '?text=' . rawurlencode($message);
    }

    public function dismissTemporaryPassword(): void
    {
        $this->temporaryPassword = null;
        $this->temporaryPasswordFor = null;
        $this->temporaryPasswordWhatsApp = null;
    }

    public function delete(int $userId): void
    {
        $user = User::findOrFail($userId);
        $viewer = auth()->user();

        if ((int) $viewer->id === (int) $user->id) {
            session()->flash('error', 'Vous ne pouvez pas supprimer votre propre compte depuis ce panneau.');
            return;
        }

        if ($user->role === User::ROLE_SYSTEM_ADMIN) {
            session()->flash('error', 'Impossible de supprimer ce compte protégé.');
            return;
        }

        $user->tokens()->delete();
        $user->delete();

        session()->flash('status', 'Utilisateur supprimé.');
    }

    public function render()
    {
        $users = User::query()
            ->when($this->search, function ($query) {
                $term = '%' . $this->search . '%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE LOWER(?)', [$term])
                        ->orWhereRaw('LOWER(email) LIKE LOWER(?)', [$term])
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('livewire.admin.users.index', ['users' => $users])
            ->layout('components.layouts.admin', ['title' => 'Utilisateurs']);
    }
}
