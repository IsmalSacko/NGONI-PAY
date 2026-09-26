<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\CodeReinitialisationMail;
use App\Models\PasswordResetCode;
use App\Support\Auth\Identification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Mot de passe oublié, en autonomie pour qui a une adresse e-mail : un code à
 * 6 chiffres est envoyé, valable quelques minutes, cinq essais. Sans e-mail,
 * l'exploitant donne un mot de passe provisoire depuis la console (WhatsApp).
 * Partagé par l'application et le back-office.
 */
class ReinitialisationMotDePasse
{
    /** Même comportement que le numéro existe ou non : rien à deviner. */
    public function demander(string $telephone, ?string $pays): void
    {
        $user = Identification::comptes($telephone, $pays)->first();

        if ($user === null || ! $user->is_active || blank($user->email)) {
            return;
        }

        $code = (string) random_int(100000, 999999);

        PasswordResetCode::where('user_id', $user->id)->delete();
        PasswordResetCode::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(PasswordResetCode::VALIDITY_MINUTES),
        ]);

        try {
            Mail::to($user->email)->send(new CodeReinitialisationMail($code, $user->name));
        } catch (\Throwable $e) {
            Log::error('Code de réinitialisation non envoyé', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    /** @throws ValidationException code faux, expiré ou épuisé */
    public function reinitialiser(string $telephone, ?string $pays, string $code, string $motDePasse): void
    {
        $invalide = ValidationException::withMessages(['code' => ['Code invalide ou expiré. Demandez-en un nouveau.']]);

        $user = Identification::comptes($telephone, $pays)->first();
        if ($user === null || ! $user->is_active) {
            throw $invalide;
        }

        $demande = PasswordResetCode::where('user_id', $user->id)->latest('id')->first();
        if ($demande === null || $demande->expires_at->isPast() || $demande->attempts >= PasswordResetCode::MAX_ATTEMPTS) {
            throw $invalide;
        }

        if (! Hash::check($code, $demande->code_hash)) {
            $demande->increment('attempts');
            throw $invalide;
        }

        $user->forceFill(['password' => $motDePasse])->save();
        $user->tokens()->delete();
        PasswordResetCode::where('user_id', $user->id)->delete();
    }
}
