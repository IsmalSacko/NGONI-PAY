<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RappelFinEssaiMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $titre, public string $texte, public ?string $nom = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->titre);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rappel-fin-essai');
    }
}
