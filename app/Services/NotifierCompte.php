<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\MessageCompteMail;
use App\Models\NotificationApp;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Prévient un compte de ce qui le concerne : cloche de l'application, push
 * sur ses téléphones et, s'il en a un, e-mail. Un envoi raté est journalisé
 * sans jamais faire échouer l'action qui l'a déclenché.
 */
class NotifierCompte
{
    public function __construct(private readonly PushFirebase $push) {}

    public function envoyer(User $user, string $titre, string $message, string $lien = '/abonnement', string $type = 'abonnement', bool $email = true): void
    {
        try {
            $notification = NotificationApp::create([
                'user_id' => $user->id, 'type' => $type, 'titre' => $titre, 'message' => $message, 'lien' => $lien,
            ]);
            $this->push->envoyerAuxComptes([$user->id], [
                'notification_id' => (string) $notification->id, 'titre' => $titre, 'message' => $message, 'lien' => $lien, 'type' => $type,
            ]);
        } catch (\Throwable $e) {
            Log::error('Notification non envoyée', ['user_id' => $user->id, 'titre' => $titre, 'erreur' => $e->getMessage()]);
        }

        if (! $email || blank($user->email)) {
            return;
        }

        try {
            Mail::to($user->email)->send(new MessageCompteMail($titre, $message, $user->name));
        } catch (\Throwable $e) {
            Log::error('E-mail non envoyé', ['user_id' => $user->id, 'titre' => $titre, 'erreur' => $e->getMessage()]);
        }
    }
}
