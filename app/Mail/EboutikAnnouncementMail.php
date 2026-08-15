<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EboutikAnnouncementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ngoni Pay évolue : découvrez E-BOUTIK',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.eboutik-announcement',
            with: [
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
        );
    }
}
