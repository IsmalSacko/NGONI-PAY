<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\Country;
use App\Models\Boutique;
use App\Models\User;
use App\Support\WhatsApp;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tentative de nouvel essai depuis un téléphone déjà utilisé : la personne, son
 * téléphone, les comptes déjà vus sur ce téléphone, et un lien WhatsApp qui
 * ouvre la conversation avec un message d'avertissement prêt à envoyer.
 */
class FraudeEssaiMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  list<array{nom: string, telephone: ?string, inscrit_le: ?string}>  $dejaVus */
    public function __construct(public User $user, public Boutique $boutique, public array $dejaVus, public ?string $appareil = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Essai refusé : téléphone déjà utilisé — '.$this->user->name);
    }

    public function content(): Content
    {
        $whatsapp = WhatsApp::link($this->user->phone);

        return new Content(
            view: 'emails.fraude-essai',
            with: [
                'user' => $this->user,
                'boutique' => $this->boutique,
                'comptes' => array_map(fn (array $c) => $c + ['whatsapp' => WhatsApp::link($c['telephone'])], $this->dejaVus),
                'appareil' => $this->appareil ?: 'Inconnu',
                'pays' => Country::tryFrom((string) $this->boutique->pays)?->label() ?? $this->boutique->pays,
                'whatsappAvertissement' => $whatsapp === null ? null : $whatsapp.'?text='.rawurlencode(self::avertissement($this->user)),
                'avertissement' => self::avertissement($this->user),
                'lienConsole' => url('/plateforme/comptes'),
            ],
        );
    }

    /** Message prêt à envoyer au commerçant, ferme mais courtois. */
    public static function avertissement(User $user): string
    {
        return "Bonjour {$user->name}, ici l'équipe Ngoni Caisse. Votre nouveau compte ({$user->phone}) a été créé depuis un téléphone "
            ."déjà utilisé pour un autre compte Ngoni Caisse. L'essai gratuit est offert une seule fois par téléphone : ce nouveau "
            ."compte n'en bénéficie donc pas. Vous pouvez continuer avec votre compte existant, ou vous abonner depuis l'application "
            ."(menu Abonnement). S'il s'agit d'une erreur, répondez-nous ici et nous regarderons ensemble.";
    }
}
