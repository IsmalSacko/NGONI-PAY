<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutDemande;
use App\Models\Abonnement;
use App\Models\DemandeAbonnement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Parrainage entre commerçants.
 *
 * - Chaque propriétaire a un code (créé à la première consultation).
 * - Le filleul le saisit à l'inscription : son essai passe à 14 jours.
 * - Le parrain gagne 30 jours quand le filleul paie son premier abonnement —
 *   c'est-à-dire quand l'exploitant approuve sa première demande, seule preuve
 *   de paiement tant que l'encaissement se fait hors de l'application. Un
 *   accès offert à la main ne compte pas.
 *
 * Les 30 jours s'ajoutent tout de suite à un abonnement payé en cours ; sinon
 * (parrain en essai ou expiré), ils attendent sa prochaine approbation : le
 * parrainage récompense un client qui paie, il ne remplace pas l'abonnement.
 */
class Parrainage
{
    public const JOURS_ESSAI_FILLEUL = 14;

    public const JOURS_PARRAIN = 30;

    /** Récompenses au plus, par parrain, sur douze mois glissants. */
    public const MAX_PAR_AN = 12;

    /** Sans 0/O ni 1/I/L : un code se dicte au téléphone. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function codePour(User $user): string
    {
        if (filled($user->code_parrainage)) {
            return $user->code_parrainage;
        }

        $prefixe = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', Str::ascii((string) $user->name)) ?: 'NGONI', 0, 4));

        do {
            $code = $prefixe.'-'.collect(range(1, 3))->map(fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])->implode('');
        } while (User::where('code_parrainage', $code)->exists());

        $user->forceFill(['code_parrainage' => $code])->save();

        return $code;
    }

    /** Parrain du code saisi à l'inscription, ou une erreur de saisie claire. */
    public function parrainPour(string $code, string $telephoneFilleul): User
    {
        $parrain = User::where('code_parrainage', strtoupper(trim($code)))->where('is_active', true)->first();

        if ($parrain === null) {
            throw ValidationException::withMessages(['code_parrainage' => ['Ce code de parrainage n’existe pas. Vérifiez-le, ou laissez le champ vide.']]);
        }

        if ($parrain->phone === $telephoneFilleul) {
            throw ValidationException::withMessages(['code_parrainage' => ['Vous ne pouvez pas utiliser votre propre code.']]);
        }

        return $parrain;
    }

    /** À l'inscription : le filleul est rattaché, son essai allongé. */
    public function lier(User $filleul, User $parrain, Abonnement $essai): void
    {
        $filleul->forceFill(['parraine_par' => $parrain->id])->save();
        $essai->update(['fin' => Carbon::parse($essai->debut)->addDays(self::JOURS_ESSAI_FILLEUL)->toDateString()]);
    }

    /**
     * Premier abonnement payé d'un filleul : le parrain gagne ses jours. Sans
     * effet pour un compte sans parrain, ou déjà compté.
     *
     * Rend le parrain quand c'est ce paiement-ci qui compte (même plafonné) :
     * la demande le garde, et la console la distingue des renouvellements.
     */
    public function recompenser(string $filleulId): ?User
    {
        $filleul = User::find($filleulId);
        if ($filleul === null || $filleul->parraine_par === null || $filleul->parrainage_recompense_le !== null) {
            return null;
        }

        $parrain = User::find($filleul->parraine_par);
        $abonnement = $parrain === null ? null : $this->abonnementDe($parrain);

        $dejaCettAnnee = $parrain === null ? 0 : User::where('parraine_par', $parrain->id)
            ->where('parrainage_recompense_le', '>=', now()->subYear())->count();

        // Noté même sans récompense (plafond atteint, parrain parti) : un
        // filleul ne rapporte qu'une fois.
        $filleul->forceFill(['parrainage_recompense_le' => now()])->save();

        if ($parrain === null || $abonnement === null || $dejaCettAnnee >= self::MAX_PAR_AN) {
            return $parrain;
        }

        if ($abonnement->estEnCours() && $abonnement->fin === null) {
            // Accès illimité : rien à ajouter, le merci reste.
            $message = "{$filleul->name} s’est abonné(e) grâce à vous. Merci ! Votre abonnement étant illimité, aucun jour n’est à ajouter.";
        } elseif ($abonnement->estEnCours() && ! $abonnement->estEssai()) {
            $fin = Carbon::parse($abonnement->fin)->addDays(self::JOURS_PARRAIN);
            $abonnement->update(['fin' => $fin->toDateString()]);
            $message = "{$filleul->name} s’est abonné(e) grâce à vous : 1 mois offert, votre abonnement court maintenant jusqu’au {$fin->format('d/m/Y')}.";
        } else {
            $abonnement->increment('jours_offerts', self::JOURS_PARRAIN);
            $message = "{$filleul->name} s’est abonné(e) grâce à vous : 1 mois offert. Il s’ajoutera à votre prochain abonnement.";
        }

        $this->prevenir($parrain, 'Merci pour votre parrainage !', $message);

        return $parrain;
    }

    /** Jours mis de côté, ajoutés à l'abonnement qui vient d'être approuvé. */
    public function appliquerJoursOfferts(Abonnement $abonnement): void
    {
        $jours = (int) $abonnement->jours_offerts;
        if ($jours <= 0 || $abonnement->fin === null) {
            return;
        }

        $abonnement->update([
            'fin' => Carbon::parse($abonnement->fin)->addDays($jours)->toDateString(),
            'jours_offerts' => 0,
        ]);
    }

    /**
     * Ce que l'écran « Parrainage » affiche.
     *
     * @return array<string, mixed>
     */
    public function resume(User $user): array
    {
        $code = $this->codePour($user);
        $filleuls = User::where('parraine_par', $user->id)->latest()->get(['name', 'created_at', 'parrainage_recompense_le']);

        return [
            'code' => $code,
            'message_partage' => 'Je gère ma boutique avec Ngoni Caisse : caisse, stock, tickets et crédit clients, même sans réseau. '
                ."Inscris-toi avec mon code {$code} : 14 jours d’essai gratuit au lieu de 7.\n".config('mobile.store_url'),
            'jours_filleul' => self::JOURS_ESSAI_FILLEUL,
            'jours_parrain' => self::JOURS_PARRAIN,
            'mois_gagnes' => $filleuls->whereNotNull('parrainage_recompense_le')->count(),
            'jours_en_attente' => (int) ($this->abonnementDe($user)?->jours_offerts ?? 0),
            'filleuls' => $filleuls->map(fn (User $f) => [
                'nom' => $f->name,
                'inscrit_le' => $f->created_at?->toDateString(),
                'abonne' => $f->parrainage_recompense_le !== null,
            ])->values(),
        ];
    }

    /**
     * Ce que la console dit d'une demande côté parrainage, ou null :
     * - en attente, filleul jamais compté : l'approuver récompensera le parrain ;
     * - approuvée et porteuse de la récompense : elle l'a fait.
     * Les renouvellements d'un filleul ne sont pas marqués — ils ne rapportent rien.
     *
     * @return array{etat: 'a_venir'|'recompense', parrain: string}|null
     */
    public function pourDemande(DemandeAbonnement $demande): ?array
    {
        if ($demande->parrain_recompense_id !== null) {
            $nom = $demande->parrainRecompense?->name;

            return $nom === null ? null : ['etat' => 'recompense', 'parrain' => $nom];
        }

        $filleul = $demande->proprietaire;
        if ($demande->statut !== StatutDemande::EnAttente || $filleul?->parraine_par === null || $filleul->parrainage_recompense_le !== null) {
            return null;
        }

        $nom = $filleul->parrain?->name;

        return $nom === null ? null : ['etat' => 'a_venir', 'parrain' => $nom];
    }

    /**
     * L'abonnement qui profite du parrainage : celui du compte, ou — pour un
     * administrateur qui n'est pas propriétaire — celui du propriétaire de sa
     * boutique, qui paie pour elle.
     */
    private function abonnementDe(User $user): ?Abonnement
    {
        $propre = $user->abonnement()->first();
        if ($propre !== null) {
            return $propre;
        }

        $proprietaire = $user->boutique()->value('proprietaire_id');

        return $proprietaire === null ? null : Abonnement::where('user_id', $proprietaire)->first();
    }

    private function prevenir(User $parrain, string $titre, string $message): void
    {
        app(NotifierCompte::class)->envoyer($parrain, $titre, $message, '/parrainage');
    }
}
