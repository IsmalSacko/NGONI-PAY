<?php

namespace App\Livewire\Admin\Users;

use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

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

        if ($desactivation) {
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

        session()->flash('status', "Compte réactivé. {$user->name} peut se reconnecter.");
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
