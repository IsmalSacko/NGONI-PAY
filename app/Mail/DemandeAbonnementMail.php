<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\DemandeAbonnement;
use App\Support\WhatsApp;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Prévient l'exploitant qu'une demande d'abonnement attend sa validation. */
class DemandeAbonnementMail extends Mailable
{
    use Queueable, SerializesModels;

    private const MOYENS = [
        'especes' => 'Espèces', 'orange_money' => 'Orange Money', 'moov_money' => 'Moov Money',
        'wave' => 'Wave', 'virement' => 'Virement bancaire',
    ];

    public function __construct(public DemandeAbonnement $demande) {}

    public function envelope(): Envelope
    {
        $boutique = $this->demande->boutique?->nom ?? 'une boutique';

        return new Envelope(subject: 'Demande d’abonnement '.ucfirst($this->demande->plan).' — '.$boutique);
    }

    public function content(): Content
    {
        $demande = $this->demande->loadMissing(['boutique', 'proprietaire.abonnement', 'demandeur']);
        $proprietaire = $demande->proprietaire;

        return new Content(
            view: 'emails.demande-abonnement',
            with: [
                'demande' => $demande,
                'proprietaire' => $proprietaire,
                'montant' => number_format($demande->montant, 0, ',', ' ').' '.$demande->devise,
                'moyen' => self::MOYENS[$demande->moyen] ?? $demande->moyen,
                'whatsapp' => WhatsApp::link($demande->telephone_contact ?: $proprietaire?->phone),
                'abonnement' => $proprietaire?->abonnement,
                'lienConsole' => url('/plateforme/demandes'),
            ],
        );
    }
}
