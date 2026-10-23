<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MoyenPaiement;
use App\Models\Depense;
use App\Models\EncaissementPressing;
use App\Models\EncaissementRestaurant;
use App\Models\ReglementCredit;
use App\Models\SessionCaisse;
use App\Models\Boutique;
use App\Models\User;
use App\Models\Vente;
use App\Support\Fuseau;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ouverture/fermeture d'une séance de caisse.
 *
 * Une séance par caissier à la fois : ouvrir une seconde séance avant
 * d'avoir fermé la première est refusé, pour qu'une vente ne se retrouve
 * jamais rattachée à la mauvaise séance faute d'avoir été fermée.
 */
class SessionCaisseService
{
    public function ouvrir(User $caissier, int $fondInitial): SessionCaisse
    {
        if ($this->courante($caissier) !== null) {
            throw ValidationException::withMessages(['session' => ['Une séance de caisse est déjà ouverte.']]);
        }

        return SessionCaisse::create([
            'user_id' => $caissier->id,
            'fond_initial' => $fondInitial,
            'statut' => 'ouverte',
            'ouverte_le' => now(),
        ]);
    }

    public function fermer(SessionCaisse $session, int $fondFinal, ?string $notes = null): SessionCaisse
    {
        if (! $session->estOuverte()) {
            throw ValidationException::withMessages(['session' => ['Cette séance est déjà fermée.']]);
        }

        $fondAttendu = $this->fondAttendu($session);

        $session->update([
            'fond_final' => $fondFinal,
            'ecart' => $fondFinal - $fondAttendu,
            'statut' => 'fermee',
            'fermee_le' => now(),
            'notes' => $notes,
        ]);

        return $session->fresh();
    }

    /**
     * Espèces entrées dans le tiroir pendant la séance (annulées exclues) :
     * ce qui a été payé des ventes — pas leur reste dû, qui n'est pas encore
     * là — et les dettes remboursées en espèces. Pressing : l'acompte entre le
     * jour du dépôt (et sort s'il est rendu) ; au retrait, il n'est pas recompté.
     */
    public function totalEspeces(SessionCaisse $session): int
    {
        $ventes = (int) $session->ventes()
            ->valides()
            ->where('moyen_paiement', MoyenPaiement::Especes)
            ->sum(DB::raw('montant_paye - acompte_deduit'));
        // Restaurant, paiement mixte : seule la part en espèces est dans le tiroir.
        foreach ($session->ventes()->valides()->whereNotNull('paiements')->get() as $mixte) {
            $recu = (int) $mixte->montant_paye - (int) $mixte->acompte_deduit;
            $ventes += ($mixte->partsParMoyen($recu)[MoyenPaiement::Especes->value] ?? 0)
                - ($mixte->moyen_paiement === MoyenPaiement::Especes ? $recu : 0);
        }
        $remboursements = (int) ReglementCredit::where('session_caisse_id', $session->id)
            ->where('moyen_paiement', MoyenPaiement::Especes->value)
            ->sum('montant');
        $acomptes = EncaissementPressing::where('session_caisse_id', $session->id)
            ->where('moyen_paiement', MoyenPaiement::Especes->value)
            ->get()->sum(fn (EncaissementPressing $e) => $e->signe());
        // Restaurant : acompte d'une commande par téléphone, son remboursement, le pourboire.
        $acomptes += EncaissementRestaurant::where('session_caisse_id', $session->id)
            ->where('moyen_paiement', MoyenPaiement::Especes->value)
            ->get()->sum(fn (EncaissementRestaurant $e) => $e->signe());

        // Toute dépense sort du tiroir, quel que soit le moyen : payée par
        // Orange Money, son équivalent est repris dans la caisse.
        $depenses = (int) Depense::where('session_caisse_id', $session->id)->sum('montant');

        return $ventes + $remboursements + $acomptes - $depenses;
    }

    /** Ce que le tiroir devrait contenir : le fond d'ouverture plus les espèces encaissées. */
    public function fondAttendu(SessionCaisse $session): int
    {
        return $session->fond_initial + $this->totalEspeces($session);
    }

    /**
     * Fond proposé à l'ouverture : l'argent laissé dans le tiroir à la
     * dernière fermeture de la boutique (quel que soit le caissier : le
     * tiroir, lui, reste le même).
     */
    public function derniereFermeture(): ?SessionCaisse
    {
        return SessionCaisse::where('statut', 'fermee')->whereNotNull('fond_final')->latest('fermee_le')->first();
    }

    public function courante(User $caissier): ?SessionCaisse
    {
        return SessionCaisse::where('user_id', $caissier->id)->where('statut', 'ouverte')->first();
    }

    /** Heure locale (pays de la boutique) du rappel « caisse encore ouverte ». */
    public const HEURE_RAPPEL = 23;

    /** Heure locale de la fermeture automatique : après les ventes de la nuit (bars, restaurants). */
    public const HEURE_FERMETURE = 4;

    /**
     * Créneau de la fermeture, de 4 h à 8 h : rattrape un passage manqué
     * (serveur arrêté à 4 h) sans jamais couper une caisse en pleine journée.
     */
    public const FIN_CRENEAU_FERMETURE = 8;

    /** Mention d'une séance fermée par Ngoni, faute de l'avoir été au comptoir. */
    public const NOTE_AUTO = 'Fermée automatiquement — non comptée';

    /**
     * Fermeture d'une caisse oubliée : sans comptage, donc sans fond compté ni
     * écart (en inventer un cacherait un manque). Datée de la dernière
     * opération de la séance, pas de l'heure du passage de Ngoni : la caisse a
     * servi ce soir-là, pas à 4 h du matin.
     */
    public function fermerAutomatiquement(SessionCaisse $session): SessionCaisse
    {
        $session->update([
            'statut' => 'fermee',
            'fond_final' => null,
            'ecart' => null,
            'fermee_le' => $this->derniereOperation($session),
            'fermee_auto' => true,
            'notes' => self::NOTE_AUTO,
        ]);

        return $session->fresh();
    }

    /** Le moment de la dernière opération de la séance (son ouverture, sans opération). */
    public function derniereOperation(SessionCaisse $session): Carbon
    {
        return collect([
            $session->ouverte_le,
            Vente::withoutBoutiqueScope()->where('session_caisse_id', $session->id)->max('created_at'),
            ReglementCredit::withoutBoutiqueScope()->where('session_caisse_id', $session->id)->max('created_at'),
            Depense::withoutBoutiqueScope()->where('session_caisse_id', $session->id)->max('created_at'),
            EncaissementPressing::withoutBoutiqueScope()->where('session_caisse_id', $session->id)->max('created_at'),
            EncaissementRestaurant::withoutBoutiqueScope()->where('session_caisse_id', $session->id)->max('created_at'),
        ])->filter()->map(fn ($d) => Carbon::parse($d))->max();
    }

    /**
     * Tâche horaire, à l'heure du pays de chaque boutique : à 23 h, rappel au
     * caissier et au propriétaire d'une caisse encore ouverte qui a servi dans
     * la journée (une fois par soir) ; entre 4 h et 8 h, fermeture automatique
     * des caisses ouvertes avant 4 h qui ont servi dans les 24 heures (le
     * créneau rattrape un passage manqué, jamais en pleine journée). Une
     * boutique endormie n'est ni rappelée ni fermée.
     *
     * @return array{rappels: int, fermees: int}
     */
    public function passageHoraire(CarbonInterface $instant): array
    {
        $ouvertes = SessionCaisse::withoutBoutiqueScope()->where('statut', 'ouverte')->get()->groupBy('boutique_id');
        $tenant = app(TenantContext::class);
        $precedente = $tenant->boutiqueId();
        $bilan = ['rappels' => 0, 'fermees' => 0];

        foreach (Boutique::whereIn('id', $ouvertes->keys())->get() as $boutique) {
            $local = Carbon::instance($instant->toDateTime())->setTimezone(Fuseau::pourPays($boutique->pays));
            $limite = $local->copy()->startOfDay()->setTime(self::HEURE_FERMETURE, 0);
            $tenant->setBoutique($boutique->id);
            try {
                foreach ($ouvertes[$boutique->id] as $session) {
                    if ($local->hour >= self::HEURE_FERMETURE && $local->hour < self::FIN_CRENEAU_FERMETURE
                        && $session->ouverte_le->lt($limite)
                        // Seulement une caisse qui a servi la veille : celle d'une
                        // boutique endormie reste comme elle est.
                        && $this->derniereOperation($session)->gte($limite->copy()->subDay())) {
                        $this->fermerAutomatiquement($session);
                        $bilan['fermees']++;
                    } elseif ($local->hour >= self::HEURE_RAPPEL
                        && ($session->rappel_le === null || $session->rappel_le->lt($local->copy()->startOfDay()))
                        // Seulement une caisse qui a servi aujourd'hui : une boutique
                        // endormie ne reçoit pas un rappel chaque soir.
                        && $this->derniereOperation($session)->gte($local->copy()->startOfDay())) {
                        $this->rappeler($boutique, $session);
                        $session->update(['rappel_le' => $instant]);
                        $bilan['rappels']++;
                    }
                }
            } finally {
                $precedente === null ? $tenant->forget() : $tenant->setBoutique($precedente);
            }
        }

        return $bilan;
    }

    /** « Votre caisse est encore ouverte » : au caissier, et au propriétaire s'il est un autre. */
    private function rappeler(Boutique $boutique, SessionCaisse $session): void
    {
        $destinataires = User::whereIn('id', array_filter([$session->user_id, $boutique->proprietaire_id]))
            ->where('is_active', true)->get();
        foreach ($destinataires as $u) {
            $soi = $u->id === $session->user_id;
            app(NotifierCompte::class)->envoyer(
                $u,
                'Caisse encore ouverte',
                $soi
                    ? 'Votre caisse est encore ouverte : comptez le tiroir et fermez-la. Sinon, Ngoni la fermera à 4 h sans comptage.'
                    : 'La caisse de '.($session->caissier?->name ?? 'un caissier').' est encore ouverte. Sans fermeture, Ngoni la fermera à 4 h sans comptage.',
                '/caisse',
                'caisse',
                email: false,
            );
        }
    }
}
