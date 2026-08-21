<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Annonce de mise à jour, rédigée depuis la console.
 *
 * Le contenu vient de la campagne et non du code : annoncer une version ne
 * demande donc pas de déploiement.
 */
class AppUpdateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly Campaign $campaign,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->campaign->subject
                ?: 'NGONI PAY — nouvelle version disponible',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.app-update',
            with: [
                'prenom' => trim(explode(' ', trim($this->user->name))[0] ?? ''),
                'message' => $this->campaign->message,
                'version' => $this->campaign->version,
                // Le lien passe par la page publique : c'est elle qui porte les
                // balises Open Graph si le destinataire le repartage.
                'lien' => $this->campaign->store_url ?: url('/telecharger'),
            ],
        );
    }
}
