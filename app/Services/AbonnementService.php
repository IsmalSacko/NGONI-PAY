<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CycleFacturation;
use App\Enums\StatutDemande;
use App\Mail\DemandeAbonnementMail;
use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\Plan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Abonnement du compte propriétaire : essai, état, limites, demandes.
 *
 * Il n'y a pas de plan gratuit. Après l'essai (offert une seule fois, à
 * l'inscription du compte), soit un abonnement en cours, soit la lecture seule.
 */
class AbonnementService
{
    /** Essai du compte, créé à l'inscription. Jamais relancé. */
    public function demarrerEssai(User $proprietaire): Abonnement
    {
        $existant = $proprietaire->abonnement()->first();
        if ($existant !== null) {
            return $existant;
        }

        $jours = Plan::parCode(Plan::ESSAI)?->joursEssai() ?? 7;

        return Abonnement::create([
            'user_id' => $proprietaire->id,
            'plan' => Plan::ESSAI,
            'debut' => now()->toDateString(),
            'fin' => now()->addDays($jours)->toDateString(),
            'est_actif' => true,
        ]);
    }

    /** Abonnement qui couvre une boutique : celui de son propriétaire. */
    public function pourBoutique(?Boutique $boutique): ?Abonnement
    {
        if ($boutique === null || $boutique->proprietaire_id === null) {
            return null;
        }

        return Abonnement::where('user_id', $boutique->proprietaire_id)->first();
    }

    public function boutiqueActive(?Boutique $boutique): bool
    {
        return $this->pourBoutique($boutique)?->estEnCours() ?? false;
    }

    /** Plan dont les limites s'appliquent (celui de l'abonnement, même expiré). */
    public function planDe(?Abonnement $abonnement): ?Plan
    {
        return $abonnement === null ? null : Plan::parCode($abonnement->plan);
    }

    public function peutCreerBoutique(User $proprietaire): bool
    {
        $abonnement = $proprietaire->abonnement()->first();

        // Un compte qui n'a encore rien (salarié qui ouvre sa première boutique)
        // démarrera son essai avec elle.
        if ($abonnement === null) {
            return true;
        }

        // Plus aucune boutique active (toutes fermées) : il doit pouvoir en
        // rouvrir une pour atteindre sa page d'abonnement. L'essai n'est pas
        // relancé ; sans abonnement en cours, la boutique reste en lecture seule.
        if ($proprietaire->boutiquesPossedees()->count() === 0) {
            return true;
        }

        if (! $abonnement->estEnCours()) {
            return false;
        }

        $max = $this->planDe($abonnement)?->max_boutiques;

        return $max === null || $proprietaire->boutiquesPossedees()->count() < $max;
    }

    /** Fonction réservée à certains plans (voir Plan::FONCTIONNALITES), abonnement en cours. */
    public function permet(?Boutique $boutique, string $fonctionnalite): bool
    {
        $abonnement = $this->pourBoutique($boutique);
        if ($abonnement === null || ! $abonnement->estEnCours()) {
            return false;
        }

        return $this->planDe($abonnement)?->inclut($fonctionnalite) ?? false;
    }

    public function peutAjouterMembre(Boutique $boutique, int $membresActuels): bool
    {
        $abonnement = $this->pourBoutique($boutique);
        if ($abonnement === null || ! $abonnement->estEnCours()) {
            return false;
        }

        $max = $this->planDe($abonnement)?->max_membres;

        return $max === null || $membresActuels < $max;
    }

    /**
     * Demande d'un plan payant. Le montant est figé au dépôt ; une seule
     * demande en attente à la fois ; l'exploitant est prévenu par mail.
     */
    public function soumettre(
        Boutique $boutique,
        User $demandeur,
        string $codePlan,
        CycleFacturation $cycle,
        ?string $moyen = null,
        ?string $note = null,
        ?string $telephoneContact = null,
        ?UploadedFile $preuve = null,
        ?string $preuveNote = null,
    ): DemandeAbonnement {
        $plan = Plan::parCode($codePlan);

        if ($plan === null || $plan->estEssai() || ! $plan->est_actif) {
            throw ValidationException::withMessages(['plan' => ['Choisissez un plan payant proposé.']]);
        }

        $tarif = $plan->tarif($cycle);
        if ($tarif === null) {
            throw ValidationException::withMessages(['cycle' => ["Cette durée n'est pas proposée pour le plan {$plan->nom}."]]);
        }

        $proprietaireId = $boutique->proprietaire_id;
        if ($proprietaireId === null) {
            throw ValidationException::withMessages(['plan' => ['Cette boutique n’a pas de propriétaire.']]);
        }

        $courant = Abonnement::where('user_id', $proprietaireId)->first();
        if ($courant !== null && $courant->est_actif && $courant->fin === null) {
            throw ValidationException::withMessages(['plan' => ['Votre abonnement est illimité. Contactez le support pour le modifier.']]);
        }

        if (DemandeAbonnement::where('user_id', $proprietaireId)->enAttente()->exists()) {
            throw ValidationException::withMessages(['plan' => ['Une demande est déjà en attente de validation.']]);
        }

        $demande = DemandeAbonnement::create([
            'user_id' => $proprietaireId,
            'demande_par' => $demandeur->id,
            'boutique_id' => $boutique->id,
            'plan' => $plan->code,
            'cycle' => $cycle,
            'mois' => $cycle->mois(),
            'montant' => $tarif->montant,
            'devise' => $tarif->devise,
            'moyen' => $moyen,
            'note' => $note,
            'telephone_contact' => $telephoneContact,
            'preuve_chemin' => $preuve?->store('preuves-abonnement', 'local'),
            'preuve_note' => $preuveNote,
            'statut' => StatutDemande::EnAttente,
        ]);

        try {
            Mail::to(config('ecaisse.notification_email'))->send(new DemandeAbonnementMail($demande));
        } catch (\Throwable $e) {
            // La demande est enregistrée et visible dans la console : un mail
            // perdu ne doit pas la faire échouer.
            Log::error('Mail de demande d’abonnement non envoyé', ['demande' => $demande->id, 'error' => $e->getMessage()]);
        }

        $alerte = ($boutique->nom ?? 'Une boutique').' demande '.$plan->nom.' ('.mb_strtolower($cycle->libelle()).') · '
            .number_format($tarif->montant, 0, ',', ' ').' '.$tarif->devise;
        app(AlertesExploitant::class)->envoyer('Demande d’abonnement', $alerte, '/console/demandes');

        return $demande;
    }

    public function annuler(DemandeAbonnement $demande): void
    {
        $this->exigerEnAttente($demande);
        $demande->update(['statut' => StatutDemande::Annulee, 'decide_le' => now()]);

        app(AlertesExploitant::class)->envoyer(
            'Demande annulée',
            ($demande->boutique?->nom ?? 'Une boutique').' a annulé sa demande '.ucfirst($demande->plan).'.',
            '/console/demandes',
        );
    }

    /** L'exploitant a constaté le paiement : le plan s'active pour la durée payée. */
    public function approuver(DemandeAbonnement $demande, User $exploitant, ?string $note = null): Abonnement
    {
        $this->exigerEnAttente($demande);

        return DB::transaction(function () use ($demande, $exploitant, $note): Abonnement {
            $abonnement = Abonnement::updateOrCreate(
                ['user_id' => $demande->user_id],
                [
                    'plan' => $demande->plan,
                    'debut' => now()->toDateString(),
                    'fin' => $this->finProjetee($demande->user_id, $demande->plan, $demande->mois)?->toDateString(),
                    'est_actif' => true,
                    'est_manuel' => false,
                    'accorde_par' => null,
                    'note_admin' => null,
                ],
            );

            $demande->update([
                'statut' => StatutDemande::Approuvee,
                'decide_le' => now(),
                'decide_par' => $exploitant->id,
                'note_decision' => $note,
            ]);

            return $abonnement;
        });
    }

    public function refuser(DemandeAbonnement $demande, User $exploitant, ?string $motif = null): void
    {
        $this->exigerEnAttente($demande);

        $demande->update([
            'statut' => StatutDemande::Refusee,
            'decide_le' => now(),
            'decide_par' => $exploitant->id,
            'note_decision' => $motif,
        ]);
    }

    /**
     * Fin de l'abonnement si `$mois` du plan `$plan` sont achetés maintenant.
     *
     * - Même plan encore en cours : les mois s'ajoutent à l'échéance.
     * - Autre plan payant encore en cours : les jours restants sont convertis en
     *   jours du plan demandé, à leur valeur (un jour Pro vaut plus qu'un jour
     *   Basic). Un essai ou un accès offert ne se convertit pas.
     * - Sinon : la durée part d'aujourd'hui.
     */
    public function finProjetee(string $proprietaireId, string $plan, int $mois): ?Carbon
    {
        $courant = Abonnement::where('user_id', $proprietaireId)->first();
        $depart = now()->startOfDay();

        if ($courant === null || ! $courant->estEnCours()) {
            return $depart->addMonths($mois);
        }

        if ($courant->fin === null) {
            return null;
        }

        $echeance = Carbon::parse($courant->fin)->startOfDay();

        if ($courant->plan === $plan) {
            return $echeance->addMonths($mois);
        }

        return $depart->addMonths($mois)->addDays($this->joursCredites($courant, $plan));
    }

    private function joursCredites(Abonnement $courant, string $plan): int
    {
        if ($courant->est_manuel || $courant->estEssai() || $courant->fin === null) {
            return 0;
        }

        $actuel = Plan::parCode($courant->plan);
        $vise = Plan::parCode($plan);
        $tauxVise = $vise?->tarifMensuel() ?? 0.0;

        if ($actuel === null || $tauxVise <= 0) {
            return 0;
        }

        $restants = (int) now()->startOfDay()->diffInDays(Carbon::parse($courant->fin)->startOfDay(), false);

        // Arrondi au jour inférieur : jamais un jour offert par un arrondi.
        return $restants <= 0 ? 0 : (int) floor($restants * $actuel->tarifMensuel() / $tauxVise);
    }

    /**
     * Accord manuel par l'exploitant (paiement reçu hors demande, geste
     * commercial, prolongation d'essai). `$fin` NULL = sans échéance. Une
     * demande en attente du même compte est soldée.
     */
    public function accorder(User $proprietaire, string $plan, ?Carbon $fin, User $exploitant, ?string $note = null): Abonnement
    {
        if (Plan::parCode($plan) === null) {
            throw ValidationException::withMessages(['plan' => ['Plan inconnu.']]);
        }

        return DB::transaction(function () use ($proprietaire, $plan, $fin, $exploitant, $note): Abonnement {
            $abonnement = Abonnement::updateOrCreate(
                ['user_id' => $proprietaire->id],
                [
                    'plan' => $plan,
                    'debut' => now()->toDateString(),
                    'fin' => $fin?->toDateString(),
                    'est_actif' => true,
                    'est_manuel' => true,
                    'accorde_par' => $exploitant->id,
                    'note_admin' => $note,
                ],
            );

            DemandeAbonnement::where('user_id', $proprietaire->id)->enAttente()->update([
                'statut' => StatutDemande::Approuvee->value,
                'decide_le' => now(),
                'decide_par' => $exploitant->id,
                'note_decision' => 'Accordé manuellement depuis la console.',
            ]);

            return $abonnement;
        });
    }

    /** Fin immédiate : plus aucune action possible dans ses boutiques. */
    public function revoquer(User $proprietaire): void
    {
        $proprietaire->abonnement()->first()?->expirerMaintenant();
    }

    private function exigerEnAttente(DemandeAbonnement $demande): void
    {
        if ($demande->statut->estTranchee()) {
            throw ValidationException::withMessages(['demande' => ['Cette demande a déjà été traitée.']]);
        }
    }
}
