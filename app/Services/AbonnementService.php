<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CycleFacturation;
use App\Enums\StatutDemande;
use App\Mail\DemandeAbonnementMail;
use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\JournalAbonnement;
use App\Models\Plan;
use App\Models\User;
use App\Support\Apres;
use App\Support\ConsoleNgoni;
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

        // La formule, ou une fonction accordée à la main par l'exploitant.
        return $abonnement->inclut($fonctionnalite);
    }

    /**
     * Le plan de la boutique inclut-il cette fonction, abonnement en cours ou
     * non : ce qui reste consultable après l'échéance (le back-office) ne
     * dépend que de l'offre.
     */
    public function planInclut(?Boutique $boutique, string $fonctionnalite): bool
    {
        return $this->pourBoutique($boutique)?->inclut($fonctionnalite) ?? false;
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
        bool $prevenir = true,
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

        // Paiement Mobile Money (Jèko) : l'exploitant n'a rien à vérifier, il
        // est prévenu quand le paiement est confirmé, pas à l'ouverture.
        if (! $prevenir) {
            return $demande;
        }

        Apres::reponse(function () use ($demande): void {
            try {
                Mail::to(config('ecaisse.notification_email'))->send(new DemandeAbonnementMail($demande));
            } catch (\Throwable $e) {
                // La demande est enregistrée et visible dans la console : un mail
                // perdu ne doit pas la faire échouer.
                Log::error('Mail de demande d’abonnement non envoyé', ['demande' => $demande->id, 'error' => $e->getMessage()]);
            }
        });

        $alerte = ($boutique->nom ?? 'Une boutique').' demande '.$plan->nom.' ('.mb_strtolower($cycle->libelle()).') · '
            .number_format($tarif->montant, 0, ',', ' ').' '.$tarif->devise;
        $parrainage = app(Parrainage::class)->pourDemande($demande);
        if ($parrainage !== null) {
            $alerte .= " · 🎁 filleul de {$parrainage['parrain']}";
        }
        app(AlertesExploitant::class)->envoyer('Demande d’abonnement', $alerte, ConsoleNgoni::demandes());

        return $demande;
    }

    public function annuler(DemandeAbonnement $demande): void
    {
        $this->exigerEnAttente($demande);
        $demande->statut = StatutDemande::Annulee;
        $demande->decide_le = now();
        // Sans cette note, rien ne distinguait dans la console un retrait du
        // commerçant d'une annulation faute de paiement. Le contrôleur a relu
        // le paiement en ligne juste avant : rien n'était reçu.
        $enLigne = $demande->libellePaiementEnLigne();
        $demande->note_decision = 'Retirée depuis l’application '.$demande->delaiDecision().' après la demande'
            .($enLigne !== null ? ', sans paiement reçu' : '').'.';
        $demande->save();

        app(AlertesExploitant::class)->envoyer(
            'Demande annulée',
            ($demande->boutique?->nom ?? 'Une boutique').' a retiré sa demande '.ucfirst($demande->plan)
                .($enLigne !== null ? ' ('.$enLigne.') '.$demande->delaiDecision().' après l’avoir lancée, sans payer.' : '.'),
            ConsoleNgoni::demandes(),
        );
    }

    /**
     * L'exploitant a constaté le paiement : le plan s'active pour la durée
     * payée, ou sans échéance quand le commerçant a payé un accès à vie.
     */
    public function approuver(DemandeAbonnement $demande, ?User $exploitant, ?string $note = null, bool $sansEcheance = false): Abonnement
    {
        $this->exigerEnAttente($demande);

        $abonnement = DB::transaction(function () use ($demande, $exploitant, $note, $sansEcheance): Abonnement {
            // Le paiement prolonge la même offre en cours à partir de son échéance, sinon commence aujourd'hui.
            $courant = Abonnement::where('user_id', $demande->user_id)->first();
            $prolonge = $courant !== null && $courant->estEnCours() && $courant->plan === $demande->plan && $courant->fin !== null && $courant->fin->isFuture();
            $periodeDebut = $prolonge ? $courant->fin->toDateString() : now()->toDateString();

            $abonnement = Abonnement::updateOrCreate(
                ['user_id' => $demande->user_id],
                [
                    'plan' => $demande->plan,
                    'debut' => now()->toDateString(),
                    'fin' => $sansEcheance ? null : $this->finProjetee($demande->user_id, $demande->plan, $demande->mois)?->toDateString(),
                    'est_actif' => true,
                    'est_manuel' => false,
                    'accorde_par' => null,
                    'note_admin' => null,
                ],
            );

            $demande->update([
                'statut' => StatutDemande::Approuvee,
                'decide_le' => now(),
                // Sans exploitant : approuvée d'elle-même, le paiement Mobile Money confirmé par Jèko.
                'decide_par' => $exploitant?->id,
                'note_decision' => $note,
            ]);

            // Un vrai paiement vient d'être constaté : les jours gagnés en
            // parrainant s'ajoutent, et le parrain de ce compte est récompensé.
            $parrainage = app(Parrainage::class);
            $parrainage->appliquerJoursOfferts($abonnement);
            $parrain = $parrainage->recompenser($demande->user_id);
            if ($parrain !== null) {
                $demande->update(['parrain_recompense_id' => $parrain->id]);
            }

            $abonnement = $abonnement->fresh();
            // Le reçu : un numéro, et la période que ce paiement couvre (jours offerts compris).
            $demande->update(['periode_debut' => $periodeDebut, 'periode_fin' => $abonnement->fin?->toDateString()]);
            $demande->update(['recu_numero' => RecuAbonnement::numero($demande)]);

            return $abonnement;
        });

        $this->prevenirActivation($abonnement, $demande->demande_par, $demande->recu_numero);
        // Payé en ligne ou validé à la main : la vente dans la boutique de l'exploitant.
        app(VenteAbonnement::class)->apresValidation($demande);

        return $abonnement;
    }

    /**
     * Le propriétaire (et le membre qui a fait la demande, si ce n'est pas lui)
     * apprend que son abonnement est actif, et jusqu'à quand.
     */
    private function prevenirActivation(Abonnement $abonnement, ?string $demandeurId = null, ?string $recu = null): void
    {
        $plan = Plan::parCode($abonnement->plan);
        $proprietaire = User::find($abonnement->user_id);
        if ($proprietaire === null) {
            return;
        }

        $jusqua = $abonnement->fin === null ? 'sans échéance' : 'jusqu’au '.$abonnement->fin->format('d/m/Y');
        [$titre, $message] = $abonnement->estEssai()
            ? ['Votre essai gratuit est prolongé', "Bonne nouvelle : votre essai gratuit est prolongé {$jusqua}. Toutes les fonctions restent ouvertes."]
            : ["Votre abonnement {$plan?->nom} est activé", "Merci pour votre confiance ! Votre abonnement {$plan?->nom} est actif {$jusqua}. Toutes ses fonctions sont ouvertes."
                .($recu !== null ? " Votre reçu n° {$recu} est disponible dans Abonnement." : '')];

        $notifier = app(NotifierCompte::class);
        $notifier->envoyer($proprietaire, $titre, $message);

        $demandeur = $demandeurId !== null && $demandeurId !== $proprietaire->id ? User::find($demandeurId) : null;
        if ($demandeur !== null) {
            $notifier->envoyer($demandeur, $titre, $message);
        }
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

        $proprietaire = User::find($demande->user_id);
        if ($proprietaire !== null) {
            $plan = Plan::parCode($demande->plan)?->nom ?? ucfirst($demande->plan);
            app(NotifierCompte::class)->envoyer(
                $proprietaire,
                'Demande d’abonnement non validée',
                "Votre demande {$plan} n’a pas pu être validée".(filled($motif) ? " : {$motif}" : '.')
                    .' Vous pouvez en envoyer une nouvelle depuis la page Abonnement, ou nous écrire sur WhatsApp.',
            );
        }
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
     * commercial, prolongation d'essai). `$fin` NULL = sans échéance, prise
     * telle quelle. Une demande en attente du même compte est soldée.
     *
     * Avec un paiement (`montant` > 0) : comme une demande validée — reçu
     * numéroté, vente dans la boutique de l'exploitant. La demande en attente
     * du compte (payée hors ligne) le porte ; sinon une demande est créée.
     *
     * @param  list<string>|null  $fonctions  fonctions accordées en plus de la formule ;
     *                                        null : celles déjà accordées restent
     * @param  array{montant?: int|null, moyen?: string|null, reference?: string|null}|null  $paiement
     * @param  string|null  $par  qui a décidé, pour le journal (défaut : l'exploitant)
     */
    public function accorder(
        User $proprietaire,
        string $plan,
        ?Carbon $fin,
        User $exploitant,
        ?string $note = null,
        ?array $fonctions = null,
        ?Carbon $debut = null,
        ?array $paiement = null,
        ?string $par = null,
    ): Abonnement {
        $modele = Plan::parCode($plan);
        if ($modele === null) {
            throw ValidationException::withMessages(['plan' => ['Plan inconnu.']]);
        }
        $accordees = $fonctions === null ? null : self::horsFormule($modele, $fonctions);
        $montant = (int) ($paiement['montant'] ?? 0);
        $par ??= $exploitant->name;

        $demande = null;
        $abonnement = DB::transaction(function () use ($proprietaire, $modele, $plan, $fin, $exploitant, $note, $accordees, $debut, $paiement, $montant, $par, &$demande): Abonnement {
            $courant = Abonnement::where('user_id', $proprietaire->id)->first();
            // Période du reçu : la date donnée, sinon la suite de la même offre en cours, sinon aujourd'hui.
            $periodeDebut = $debut ?? ($courant !== null && $courant->estEnCours() && $courant->plan === $plan && $courant->fin?->isFuture()
                ? $courant->fin->copy() : now());

            $abonnement = Abonnement::updateOrCreate(
                ['user_id' => $proprietaire->id],
                [
                    'plan' => $plan,
                    'debut' => ($debut ?? now())->toDateString(),
                    'fin' => $fin?->toDateString(),
                    'est_actif' => true,
                    'est_manuel' => true,
                    'accorde_par' => $exploitant->id,
                    'note_admin' => $note,
                    ...($accordees === null ? [] : ['fonctions_accordees' => $accordees]),
                ],
            );

            if ($montant > 0) {
                $demande = $this->demandePayee($proprietaire, $modele, $periodeDebut, $fin, $exploitant, $note, $paiement ?? [], $montant, $par);
            }

            DemandeAbonnement::where('user_id', $proprietaire->id)->enAttente()->update([
                'statut' => StatutDemande::Approuvee->value,
                'decide_le' => now(),
                'decide_par' => $exploitant->id,
                'decide_par_nom' => $par,
                'note_decision' => 'Accordé manuellement depuis la console.',
            ]);

            JournalAbonnement::create([
                'user_id' => $proprietaire->id,
                'type' => JournalAbonnement::ACCORD,
                'plan' => $plan,
                'debut' => $periodeDebut->toDateString(),
                'fin' => $fin?->toDateString(),
                'montant' => $montant > 0 ? $montant : null,
                'devise' => $montant > 0 ? $demande?->devise : null,
                'moyen' => $paiement['moyen'] ?? null,
                'reference' => $paiement['reference'] ?? null,
                'demande_id' => $demande?->id,
                'par' => $par,
                'note' => $note,
            ]);

            return $abonnement;
        });

        $this->prevenirActivation($abonnement, null, $demande?->recu_numero);
        if ($demande !== null) {
            app(VenteAbonnement::class)->apresValidation($demande);
        }

        return $abonnement;
    }

    /**
     * Le paiement d'un accord, écrit comme une demande validée : la demande en
     * attente du compte payée hors ligne (pas un paiement en ligne en cours),
     * sinon une nouvelle. Elle porte le reçu et compte dans les recettes.
     *
     * @param  array{moyen?: string|null, reference?: string|null}  $paiement
     */
    private function demandePayee(User $proprietaire, Plan $plan, Carbon $debut, ?Carbon $fin, User $exploitant, ?string $note, array $paiement, int $montant, string $par): DemandeAbonnement
    {
        // Durée payée en mois (au moins un) ; la durée la plus proche pour le libellé du reçu.
        $mois = $fin === null ? 0 : max(1, min(255, (int) round($debut->copy()->startOfDay()->diffInDays($fin->copy()->startOfDay(), true) / 30.4)));
        $cycle = collect(CycleFacturation::cases())->filter(fn (CycleFacturation $c) => $c->mois() <= max(1, $mois))->last() ?? CycleFacturation::Mensuel;
        if ($fin === null) {
            $cycle = CycleFacturation::Annuel;
        }

        $demande = DemandeAbonnement::where('user_id', $proprietaire->id)->enAttente()
            ->whereNull('jeko_paiement_id')->whereNull('fedapay_transaction_id')->whereNull('pawapay_deposit_id')
            ->oldest('id')->first()
            ?? new DemandeAbonnement([
                'user_id' => $proprietaire->id,
                'demande_par' => null,
                'boutique_id' => $proprietaire->boutiquesPossedees()->withoutGlobalScopes()->orderBy('created_at')->value('id'),
                'telephone_contact' => $proprietaire->phone,
            ]);

        $demande->fill([
            'plan' => $plan->code,
            'cycle' => $cycle,
            'mois' => $mois,
            'montant' => $montant,
            'devise' => $demande->devise ?? $plan->tarifs->first()?->devise ?? 'XOF',
            'moyen' => $paiement['moyen'] ?? $demande->moyen,
            'reference' => $paiement['reference'] ?? null,
            'statut' => StatutDemande::Approuvee,
            'decide_le' => now(),
            'decide_par' => $exploitant->id,
            'decide_par_nom' => $par,
            'note_decision' => trim('Accordé depuis la console, paiement reçu. '.($note ?? '')),
            'periode_debut' => $debut->toDateString(),
            'periode_fin' => $fin?->toDateString(),
        ])->save();
        $demande->update(['recu_numero' => RecuAbonnement::numero($demande)]);

        return $demande;
    }

    /**
     * Fonctions accordées en plus de la formule, sans changer le reste de
     * l'abonnement (options du compte dans la Console Ngoni).
     *
     * @param  list<string>  $fonctions
     */
    public function reglerFonctions(User $proprietaire, array $fonctions): Abonnement
    {
        $abonnement = $proprietaire->abonnement()->first();
        if ($abonnement === null) {
            throw ValidationException::withMessages(['fonctions' => ['Ce compte n’a pas encore d’abonnement.']]);
        }
        $modele = $abonnement->planModele();
        $abonnement->update(['fonctions_accordees' => $modele === null ? [] : self::horsFormule($modele, $fonctions)]);

        return $abonnement->fresh();
    }

    /**
     * Seules les fonctions hors de la formule s'accordent : celles qu'elle
     * inclut ne se retirent pas, et n'ont pas à être gardées en double.
     *
     * @param  list<string>  $fonctions
     * @return list<string>
     */
    private static function horsFormule(Plan $modele, array $fonctions): array
    {
        return array_values(array_filter(
            array_intersect(array_keys(Plan::FONCTIONNALITES), $fonctions),
            fn (string $f) => ! $modele->inclut($f),
        ));
    }

    /** Fin immédiate : plus aucune action possible dans ses boutiques. Notée au journal du compte. */
    public function revoquer(User $proprietaire, ?User $exploitant = null, ?string $motif = null, ?string $par = null): void
    {
        $abonnement = $proprietaire->abonnement()->first();
        if ($abonnement === null) {
            return;
        }
        $plan = $abonnement->plan;
        $abonnement->expirerMaintenant();
        JournalAbonnement::create([
            'user_id' => $proprietaire->id,
            'type' => JournalAbonnement::REVOCATION,
            'plan' => $plan,
            'fin' => $abonnement->fin?->toDateString(),
            'par' => $par ?? $exploitant?->name,
            'note' => $motif,
        ]);
    }

    private function exigerEnAttente(DemandeAbonnement $demande): void
    {
        if ($demande->statut->estTranchee()) {
            throw ValidationException::withMessages(['demande' => ['Cette demande a déjà été traitée.']]);
        }
    }
}
