<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Enums\Country;
use App\Models\User;
use App\Support\Phone\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.guest')]
class Login extends Component
{
    public string $telephone = '';

    /** Pays du numéro : décide de l'indicatif ajouté à un numéro local. */
    public string $pays = '';

    public string $password = '';

    public function mount(): void
    {
        $this->pays = Country::default()->value;
    }

    public function connexion(): void
    {
        $this->validate([
            'telephone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $candidats = PhoneNumber::candidates($this->telephone, Country::tryFrom($this->pays) ?? Country::default());

        $user = User::withoutGlobalScopes()->whereIn('phone', $candidats)->first();

        if ($user === null || ! $user->is_active || ! Hash::check($this->password, $user->password)) {
            $this->addError('telephone', 'Identifiants incorrects.');

            return;
        }

        Auth::login($user, remember: true);
        session()->regenerate();

        // L'exploitant arrive sur sa console ; un commerçant sur sa boutique.
        $this->redirect(
            $user->est_admin_plateforme ? route('plateforme.tableau') : route('tableau-de-bord'),
            navigate: false,
        );
    }

    public function render()
    {
        return view('livewire.auth.login', ['listePays' => Country::cases()]);
    }
}
