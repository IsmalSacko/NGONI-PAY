<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\WhatsApp;
use Illuminate\Validation\ValidationException;

/** Gestes de l'exploitant sur les comptes, communs à la console web et à l'application. */
class ComptesPlateforme
{
    /** Active ou désactive un compte ; désactivé, il est déconnecté partout. */
    public function basculer(User $user, User $exploitant): User
    {
        if ($user->id === $exploitant->id) {
            throw ValidationException::withMessages(['compte' => ['Vous ne pouvez pas désactiver votre propre compte.']]);
        }

        $user->update(['is_active' => ! $user->is_active]);
        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return $user;
    }

    /**
     * Mot de passe provisoire, lisible et facile à dicter (ni 0/O ni 1/l), et
     * le lien WhatsApp pour l'envoyer au commerçant.
     *
     * @return array{mot_de_passe: string, message: string, whatsapp: ?string}
     */
    public function motDePasseProvisoire(User $user, User $exploitant): array
    {
        if ($user->est_admin_plateforme && $user->id !== $exploitant->id) {
            throw ValidationException::withMessages(['compte' => ['Compte protégé : réinitialisation impossible depuis la console.']]);
        }

        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $motDePasse = '';
        for ($i = 0; $i < 10; $i++) {
            $motDePasse .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $user->forceFill(['password' => $motDePasse])->save();
        $user->tokens()->delete();

        $message = "Bonjour {$user->name}, votre mot de passe Ngoni Caisse provisoire est : {$motDePasse}\n"
            .'Connectez-vous puis changez-le.';
        $lien = WhatsApp::link($user->phone);

        return [
            'mot_de_passe' => $motDePasse,
            'message' => $message,
            'whatsapp' => $lien === null ? null : $lien.'?text='.rawurlencode($message),
        ];
    }
}
