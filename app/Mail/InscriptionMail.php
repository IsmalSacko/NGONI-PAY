<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Boutique;
use App\Models\User;
use App\Support\WhatsApp;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Prévient l'exploitant d'une inscription (nouveau compte et sa boutique) ou
 * d'une boutique ouverte par un compte existant : de quoi appeler le
 * commerçant pour l'accompagner pendant son essai.
 */
class InscriptionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public Boutique $boutique, public bool $nouveauCompte = true) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->nouveauCompte ? 'Nouvelle inscription — ' : 'Nouvelle boutique — ').$this->boutique->nom);
    }

    public function content(): Content
    {
        $abonnement = $this->user->abonnement()->first();

        return new Content(
            view: 'emails.inscription',
            with: [
                'user' => $this->user,
                'boutique' => $this->boutique,
                'nouveauCompte' => $this->nouveauCompte,
                'pays' => \App\Enums\Country::tryFrom((string) $this->boutique->pays)?->label() ?? $this->boutique->pays,
                'whatsapp' => WhatsApp::link($this->user->phone),
                'finEssai' => $abonnement?->fin?->format('d/m/Y'),
                'lienConsole' => url('/plateforme/comptes'),
            ],
        );
    }
}
