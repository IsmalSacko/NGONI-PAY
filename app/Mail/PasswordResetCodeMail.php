<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $code, public ?string $name = null)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre code de réinitialisation Ngoni Pay');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset-code');
    }
}
