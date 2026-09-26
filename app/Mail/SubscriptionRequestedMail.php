<?php

namespace App\Mail;

use App\Models\SubscriptionRequest;
use App\Support\WhatsApp;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Prévient l'exploitant qu'une demande d'abonnement attend sa validation.
 * La cloche de la console ne se voit que console ouverte : le mail, lui, arrive.
 */
class SubscriptionRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    private const METHOD_LABELS = [
        'cash' => 'Espèces',
        'orange_money' => 'Orange Money',
        'moov_money' => 'Moov Money',
        'wave' => 'Wave',
        'bank_transfer' => 'Virement bancaire',
    ];

    public function __construct(public SubscriptionRequest $subscriptionRequest)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Demande d\'abonnement ' . ucfirst($this->subscriptionRequest->plan)
                . ' — ' . $this->subscriptionRequest->business->name,
        );
    }

    public function content(): Content
    {
        $demande = $this->subscriptionRequest->loadMissing(['business.owner', 'business.subscription', 'requestedBy']);
        $business = $demande->business;
        $owner = $business->owner;
        $requester = $demande->requestedBy;

        return new Content(
            view: 'emails.subscription-requested',
            with: [
                'demande' => $demande,
                'business' => $business,
                'owner' => $owner,
                'requester' => $requester,
                'amount' => number_format((float) $demande->amount_due, 0, ',', ' ') . ' ' . $demande->currency,
                'cycle' => $demande->cycle?->label(),
                'method' => self::METHOD_LABELS[$demande->method] ?? $demande->method,
                'ownerWhatsApp' => WhatsApp::link($owner?->phone),
                'contactWhatsApp' => WhatsApp::link($demande->contact_phone),
                'subscription' => $business->subscription,
                'requestsUrl' => route('admin.subscription-requests.index'),
                'businessUrl' => route('admin.businesses.show', $business),
            ],
        );
    }
}
