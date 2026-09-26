<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Enums\Country;
use App\Services\ReinitialisationMotDePasse;
use App\Support\WhatsApp;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Mot de passe oublié sur le site : en autonomie par code reçu par e-mail, ou
 * par l'exploitant sur WhatsApp pour qui n'a pas d'adresse (mot de passe
 * provisoire donné depuis la console).
 */
#[Layout('layouts.guest')]
class MotDePasseOublie extends Component
{
    /** demande | code | termine */
    public string $etape = 'demande';

    public string $pays = '';

    public string $telephone = '';

    public string $code = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->pays = Country::default()->value;
    }

    public function demander(ReinitialisationMotDePasse $service): void
    {
        $this->validate(['telephone' => ['required', 'string', 'max:30'], 'pays' => ['required', 'string', 'size:2']]);

        if (! RateLimiter::attempt('reinit-web:'.request()->ip(), 5, fn () => null, 600)) {
            $this->addError('telephone', 'Trop de demandes. Réessayez dans quelques minutes.');

            return;
        }

        $service->demander($this->telephone, $this->pays);
        $this->etape = 'code';
    }

    public function reinitialiser(ReinitialisationMotDePasse $service): void
    {
        $this->validate([
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! RateLimiter::attempt('reinit-web-code:'.request()->ip(), 10, fn () => null, 600)) {
            $this->addError('code', 'Trop d’essais. Réessayez dans quelques minutes.');

            return;
        }

        try {
            $service->reinitialiser($this->telephone, $this->pays, $this->code, $this->password);
        } catch (ValidationException $e) {
            $this->addError('code', collect($e->errors())->flatten()->first());

            return;
        }

        $this->reset(['code', 'password', 'password_confirmation']);
        $this->etape = 'termine';
    }

    public function render()
    {
        $support = (string) config('ecaisse.support_whatsapp');
        $message = 'Bonjour, j’ai oublié mon mot de passe e-caisse. Mon numéro : '.$this->telephone;

        return view('livewire.auth.mot-de-passe-oublie', [
            'listePays' => Country::cases(),
            'lienWhatsApp' => ($lien = WhatsApp::link($support)) ? $lien.'?text='.rawurlencode($message) : null,
            'support' => $support,
        ]);
    }
}
