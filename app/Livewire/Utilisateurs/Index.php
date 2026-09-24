<?php

declare(strict_types=1);

namespace App\Livewire\Utilisateurs;

use App\Enums\Country;
use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\User;
use App\Support\Phone\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique;

    public bool $modaleOuverte = false;

    public string $name = '';

    public string $telephone = '';

    public string $password = '';

    public string $role = 'caissier';

    public function nouveauCompte(): void
    {
        $this->resetValidation();
        $this->reset(['name', 'telephone', 'password']);
        $this->role = 'caissier';
        $this->modaleOuverte = true;
    }

    public function enregistrer(): void
    {
        Auth::user()->can('utilisateurs.create') || abort(403);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'telephone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['admin', 'gerant', 'caissier'])],
        ]);

        $pays = Country::tryFrom(Auth::user()->boutique->pays) ?? Country::default();

        $user = User::create([
            'boutique_id' => Auth::user()->boutique_id,
            'name' => $data['name'],
            'phone' => PhoneNumber::normalize($data['telephone'], $pays),
            'password' => Hash::make($data['password']),
        ]);
        $user->assignRole($data['role']);

        $this->modaleOuverte = false;
    }

    public function basculerActivation(string $userId): void
    {
        Auth::user()->can('utilisateurs.update') || abort(403);

        $user = User::where('boutique_id', Auth::user()->boutique_id)->findOrFail($userId);

        if ($user->id === Auth::id()) {
            return;
        }

        $user->update(['is_active' => ! $user->is_active]);
    }

    public function render()
    {
        $membres = User::where('boutique_id', Auth::user()->boutique_id)
            ->with('roles')
            ->orderBy('name')
            ->get();

        return view('livewire.utilisateurs.index', ['membres' => $membres]);
    }
}
