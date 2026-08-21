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
            $months = max(1, (int) $request->months);

            // Un abonnement encore valide n'est pas écourté : les mois demandés
            // s'ajoutent à ce qui reste.
            $current = $business->subscription;
            $depart = $current && $current->ends_at && $current->ends_at->isFuture()
                && $current->plan === $request->plan
                ? $current->ends_at
                : now();

            Subscription::updateOrCreate(
                ['business_id' => $business->id],
                [
                    'plan' => $request->plan,
                    'is_active' => true,
                    'starts_at' => now(),
                    'ends_at' => $depart->copy()->addMonths($months),
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
