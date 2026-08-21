<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionRequestStatus;
use App\Models\Business;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cycle de vie d'une demande d'abonnement.
 *
 * Le règlement se fait hors application — espèces, mobile money, virement — donc
 * le commerçant ne peut pas s'accorder un plan payant : il dépose une demande,
 * l'exploitant l'approuve quand l'argent est constaté, et c'est l'approbation
 * qui ouvre l'accès.
 *
 * Toute la logique vit ici plutôt que dans les contrôleurs : la demande est
 * déposée depuis l'application, tranchée depuis l'API d'administration et depuis
 * le panneau web, et ces trois chemins doivent accorder exactement la même chose.
 */
class SubscriptionRequestService
{
    /**
     * Devise de facturation de l'éditeur.
     *
     * L'abonnement est vendu par NGONI PAY, dans sa monnaie, quelle que soit
     * celle dans laquelle le business tient ses comptes. Elle reste portée par
     * chaque tarif : c'est lui qui la fixe.
     */
    public const CURRENCY = 'XOF';

    /**
     * Plan payant de ce code, ou `null`.
     *
     * Les prix vivaient dans le code, à trois endroits. Ils sont désormais tenus
     * par l'exploitant depuis la console — un ajustement ne demande plus de
     * déploiement.
     */
    public function planFor(string $code): ?SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->with('prices')
            ->active()
            ->where('code', strtolower(trim($code)))
            ->first();
    }

    /**
     * Dépose une demande.
     *
     * Une seule demande en attente par business : sans cette borne, un commerçant
     * impatient en dépose dix, et l'exploitant instruit dix fois le même dossier.
     *
     * @param  \Illuminate\Http\UploadedFile|null  $proof  Photo du reçu ou capture du SMS.
     */
    public function submit(
        Business $business,
        string $plan,
        ?User $requestedBy = null,
        ?string $method = null,
        BillingCycle $cycle = BillingCycle::Monthly,
        ?string $note = null,
        ?string $contactPhone = null,
        $proof = null,
        ?string $proofNote = null,
    ): SubscriptionRequest {
        $modele = $this->planFor($plan);

        if ($modele === null || $modele->isFree()) {
            throw ValidationException::withMessages([
                'plan' => ["Seuls les plans payants font l'objet d'une demande."],
            ]);
        }

        $tarif = $modele->priceFor($cycle);

        if ($tarif === null) {
            throw ValidationException::withMessages([
                'cycle' => [
                    'Cette durée n\'est pas proposée pour le plan ' . $modele->name . '.',
                ],
            ]);
        }

        // Abonnement sans échéance : lui vendre du temps serait prendre son
        // argent pour rien, puisqu'il en a déjà sans limite.
        $courant = $business->subscription;

        if ($courant !== null && $courant->is_active && $courant->ends_at === null) {
            throw ValidationException::withMessages([
                'plan' => [
                    'Votre abonnement est illimité. Contactez le service client '
                        . 'pour toute modification.',
                ],
            ]);
        }

        $pending = SubscriptionRequest::query()
            ->where('business_id', $business->id)
            ->pending()
            ->first();

        if ($pending) {
            throw ValidationException::withMessages([
                'plan' => [
                    'Une demande est déjà en attente de validation pour ce business.',
                ],
            ]);
        }

        return SubscriptionRequest::create([
            'business_id' => $business->id,
            'requested_by_user_id' => $requestedBy?->id,
            'plan' => $modele->code,
            'method' => $method,
            // Le montant est figé au dépôt : un tarif que l'exploitant ajuste
            // entre-temps ne doit pas transformer ce qui a été demandé.
            'amount_due' => $tarif->amount,
            'currency' => $tarif->currency,
            'cycle' => $cycle->value,
            'months' => $tarif->months(),
            'note' => $note,
            'contact_phone' => $contactPhone,
            'proof_path' => $this->storeProof($proof),
            'proof_note' => $proofNote,
            'status' => SubscriptionRequestStatus::Pending,
        ]);
    }

    /**
     * Le commerçant retire sa demande — erreur de plan, changement d'avis.
     */
    public function cancel(SubscriptionRequest $request): SubscriptionRequest
    {
        $this->ensurePending($request);

        $request->update([
            'status' => SubscriptionRequestStatus::Cancelled,
            'decided_at' => now(),
        ]);

        return $request->fresh();
    }

    /**
     * Date de fin qu'accorderait l'approbation de cette demande.
     *
     * Le point délicat : que devient le temps déjà payé quand le commerçant
     * change de plan ? Le jeter le vole, le reporter tel quel le paie trop —
     * trente jours de Basic ne valent pas trente jours de Pro.
     *
     * Ce qui reste n'est donc pas compté en jours mais en **valeur**, ramenée au
     * tarif mensuel du plan concerné, puis reconvertie en jours du plan demandé :
     *
     *     créditJours = joursRestants × tarifMensuel(actuel) / tarifMensuel(demandé)
     *
     * Une seule règle, et chaque cas en découle :
     * - même plan encore valide → extension exacte depuis son échéance, sans
     *   passer par la valeur : le calendrier est plus juste qu'un mois de trente
     *   jours ;
     * - montée en gamme → les jours restants valent moins cher, donc moins de
     *   jours ;
     * - descente en gamme → ils valent davantage, donc plus de jours ;
     * - essai gratuit → tarif nul, aucun crédit : il n'a rien coûté ;
     * - attribution manuelle → aucun crédit : une faveur ne se convertit pas en
     *   jours payants, et un cadeau de dix ans multiplierait sinon.
     *
     * Rend `null` pour un abonnement sans échéance, qu'on ne raccourcit jamais.
     */
    public function projectedEndDate(SubscriptionRequest $request): ?Carbon
    {
        return $this->projectFor(
            $request->business,
            (string) $request->plan,
            max(1, (int) $request->months),
        );
    }

    /**
     * Ce que donnerait l'achat de `$mois` mois du plan `$plan`.
     *
     * Séparé de la demande pour que l'application puisse l'annoncer **avant**
     * qu'elle soit déposée : dupliquer la formule côté mobile la ferait dériver
     * du serveur au premier ajustement de tarif.
     */
    public function projectFor(Business $business, string $plan, int $mois): ?Carbon
    {
        $mois = max(1, $mois);
        $courant = $business->subscription;

        // Rien en cours : la durée part de maintenant.
        if ($courant === null) {
            return now()->addMonths($mois);
        }

        // Abonnement sans échéance : il ne se raccourcit pas.
        if ($courant->is_active && $courant->ends_at === null) {
            return null;
        }

        $echeance = $courant->ends_at ? Carbon::parse($courant->ends_at) : null;
        $encoreValide = $echeance !== null && $echeance->isFuture();

        // Même plan encore valide : extension exacte, en mois calendaires.
        if ($encoreValide && $courant->plan === $plan) {
            return $echeance->copy()->addMonths($mois);
        }

        $depart = now()->addMonths($mois);

        if (! $encoreValide) {
            return $depart;
        }

        return $depart->addDays($this->creditedDays($courant, $plan));
    }

    /**
     * Détail de la projection, pour l'annoncer au commerçant.
     *
     * @return array{ends_at: ?string, remaining_days: int, credited_days: int, extends: bool}
     */
    public function previewFor(Business $business, string $plan, int $mois): array
    {
        $courant = $business->subscription;
        $echeance = $courant?->ends_at ? Carbon::parse($courant->ends_at) : null;
        $restants = $echeance !== null && $echeance->isFuture()
            ? (int) ceil(now()->diffInDays($echeance, false))
            : 0;

        $prolonge = $courant !== null
            && $courant->plan === $plan
            && $restants > 0;

        return [
            'ends_at' => $this->projectFor($business, $plan, $mois)?->toIso8601String(),
            'remaining_days' => max(0, $restants),
            // Sur le même plan les jours restants sont conservés tels quels : il
            // n'y a pas de conversion, donc pas de crédit à annoncer.
            'credited_days' => $prolonge
                ? max(0, $restants)
                : $this->creditedDays($courant ?? new Subscription(), $plan),
            'extends' => $prolonge,
        ];
    }

    /**
     * Jours du plan demandé que vaut le reste de l'abonnement en cours.
     */
    private function creditedDays(Subscription $courant, string $plan): int
    {
        // Une attribution manuelle n'a pas été payée : la convertir en jours d'un
        // plan payant transformerait une faveur en argent.
        if ($courant->is_manual || $courant->ends_at === null) {
            return 0;
        }

        $actuel = SubscriptionPlan::byCode($courant->plan);
        $demande = SubscriptionPlan::byCode($plan);

        if ($actuel === null || $demande === null) {
            return 0;
        }

        $tauxDemande = $demande->monthlyRate();

        // Plan demandé gratuit — cas écarté au dépôt — ou tarif nul : rien à
        // diviser.
        if ($tauxDemande <= 0) {
            return 0;
        }

        $joursRestants = now()->diffInDays(Carbon::parse($courant->ends_at), false);

        if ($joursRestants <= 0) {
            return 0;
        }

        // Arrondi au jour inférieur : mieux vaut un jour de moins qu'un jour
        // offert par un arrondi, répété sur chaque renouvellement.
        return (int) floor($joursRestants * $actuel->monthlyRate() / $tauxDemande);
    }

    /**
     * L'exploitant approuve : le plan s'active, et l'encaissement est tracé.
     *
     * Le paiement écrit ici porte `purpose = subscription`, donc n'entre pas dans
     * le chiffre d'affaires du commerçant — c'est sa dépense, pas sa recette.
     */
    public function approve(
        SubscriptionRequest $request,
        User $decidedBy,
        ?string $note = null,
    ): SubscriptionRequest {
        $this->ensurePending($request);

        return DB::transaction(function () use ($request, $decidedBy, $note) {
            $business = $request->business;

            Subscription::updateOrCreate(
                ['business_id' => $business->id],
                [
                    'plan' => $request->plan,
                    'is_active' => true,
                    'starts_at' => now(),
                    // Voir `projectedEndDate` : ce qui reste est converti en
                    // jours du plan demandé, à sa valeur.
                    'ends_at' => $this->projectedEndDate($request),
                ],
            );

            $this->tracePayment($request, $decidedBy);

            $request->update([
                'status' => SubscriptionRequestStatus::Approved,
                'decided_at' => now(),
                'decided_by_user_id' => $decidedBy->id,
                'decision_note' => $note,
            ]);

            return $request->fresh(['business.subscription']);
        });
    }

    /**
     * Solde la demande en attente lorsqu'un plan est accordé par un autre chemin.
     *
     * L'exploitant peut attribuer un plan depuis la page « Abonnements », sans
     * passer par la file des demandes. Ce chemin n'y touchait pas : la demande
     * restait « en attente » indéfiniment, alors que le commerçant avait bien
     * son abonnement — l'exploitant voyait un dossier à instruire qui ne
     * l'était plus.
     *
     * Aucun encaissement n'est écrit ici, contrairement à {@see approve()} : une
     * attribution manuelle peut être une faveur, et inventer un paiement serait
     * pire que de n'en écrire aucun.
     */
    public function markGrantedManually(
        Business $business,
        User $decidedBy,
        ?string $plan = null,
    ): ?SubscriptionRequest {
        $demande = SubscriptionRequest::query()
            ->where('business_id', $business->id)
            ->pending()
            ->when($plan !== null, fn ($query) => $query->where('plan', $plan))
            ->latest()
            ->first();

        if ($demande === null) {
            return null;
        }

        $demande->update([
            'status' => SubscriptionRequestStatus::Approved,
            'decided_at' => now(),
            'decided_by_user_id' => $decidedBy->id,
            'decision_note' => 'Accordé manuellement depuis la console.',
        ]);

        return $demande->fresh();
    }

    public function refuse(
        SubscriptionRequest $request,
        User $decidedBy,
        ?string $reason = null,
    ): SubscriptionRequest {
        $this->ensurePending($request);

        $request->update([
            'status' => SubscriptionRequestStatus::Refused,
            'decided_at' => now(),
            'decided_by_user_id' => $decidedBy->id,
            'decision_note' => $reason,
        ]);

        return $request->fresh();
    }

    /**
     * Trace l'encaissement de l'abonnement approuvé.
     *
     * Le client porteur est celui du business — un abonnement n'a pas de client au
     * sens commercial, mais la colonne l'exige.
     */
    private function tracePayment(SubscriptionRequest $request, User $decidedBy): void
    {
        $business = $request->business;

        $client = Client::firstOrCreate(
            [
                'business_id' => $business->id,
                'phone' => $business->phone ?: 'ABONNEMENT-' . $business->id,
            ],
            ['name' => $business->name ?: 'Abonnement'],
        );

        Payment::create([
            'business_id' => $business->id,
            'client_id' => $client->id,
            'user_id' => $decidedBy->id,
            'amount' => $request->amount_due,
            'currency' => $request->currency,
            'method' => $request->method ?: 'cash',
            'provider' => null,
            'purpose' => Payment::PURPOSE_SUBSCRIPTION,
            'status' => 'success',
            'paid_at' => now(),
            'transaction_ref' => (string) Str::uuid(),
        ]);
    }

    private function ensurePending(SubscriptionRequest $request): void
    {
        if ($request->status->isDecided()) {
            throw ValidationException::withMessages([
                'status' => [
                    'Cette demande a déjà été tranchée (' . $request->status->label() . ').',
                ],
            ]);
        }
    }

    /**
     * Range la preuve jointe, s'il y en a une.
     *
     * Facultative : un commerçant qui paie de la main à la main n'a rien à
     * montrer, et exiger une pièce jointe bloquerait sa demande.
     */
    private function storeProof($proof): ?string
    {
        if ($proof === null) return null;

        return $proof->storePublicly('subscription-proofs', 'public') ?: null;
    }
}
