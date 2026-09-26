<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Annonce;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AnnonceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Annonce $annonce, public ?string $nom = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->annonce->titre);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.annonce');
    }
}
