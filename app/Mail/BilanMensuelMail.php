<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Le bilan du mois d'une boutique, en pièce jointe PDF. */
class BilanMensuelMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $titre,
        public string $texte,
        public ?string $nom,
        private readonly string $pdf,
        private readonly string $fichier,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->titre);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.message-compte');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->fichier)->withMime('application/pdf')];
    }
}
