<?php

use App\Enums\BillingCycle;
use App\Models\Business;
use App\Models\SubscriptionPlan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Les tarifs étaient écrits dans le code, à trois endroits : les ajuster
 * demandait un déploiement, et l'abonnement n'existait qu'au mois.
 */
beforeEach(function () {
    $this->owner = User::factory()->create(['role' => 'owner']);

    $this->business = Business::create([
        'owner_id' => $this->owner->id,
        'name' => 'Boutique Test',
        'type' => 'shop',
        'currency' => 'XOF',
    ]);
    $this->business->staff()->attach($this->owner->id, ['role' => 'manager']);

    Subscription::create([
        'business_id' => $this->business->id,
        'plan' => 'free',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(6),
        'is_active' => true,
    ]);

    Sanctum::actingAs($this->owner);
});

it('publie le catalogue des plans sans authentification', function () {
    $reponse = $this->getJson('/api/subscription-plans')->assertOk();

    $plans = collect($reponse->json('data'))->keyBy('code');

    expect($plans->keys())->toContain('free', 'basic', 'pro')
        ->and($plans['free']['is_free'])->toBeTrue()
        ->and($plans['free']['trial_days'])->toBe(7);

    // Quatre durées par plan payant : le mensuel seul obligeait un commerçant
    // qui voulait régler son année à revenir douze fois.
    $cycles = collect($plans['basic']['prices'])->pluck('cycle');

    expect($cycles)->toContain('monthly', 'quarterly', 'biannual', 'yearly');
});

it('reprend les tarifs qui étaient codés en dur', function () {
    $plans = collect($this->getJson('/api/subscription-plans')->json('data'))
        ->keyBy('code');

    $mensuel = fn (array $plan) => collect($plan['prices'])
        ->firstWhere('cycle', 'monthly')['amount'];

    expect($mensuel($plans['basic']))->toEqual(5000)
        ->and($mensuel($plans['pro']))->toEqual(15000);
});

it('facture la durée choisie, au tarif que l’exploitant a fixé', function () {
    // Une remise sur l'année : rien n'impose qu'elle vaille douze fois le mois.
    $pro = SubscriptionPlan::where('code', 'pro')->firstOrFail();
    $pro->prices()->where('cycle', 'yearly')->update(['amount' => 150000]);

    $reponse = $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'pro', 'cycle' => 'yearly', 'method' => 'cash'],
    )->assertStatus(202);

    expect($reponse->json('data.amount_due'))->toEqual(150000)
        ->and($reponse->json('data.months'))->toBe(12)
        ->and($reponse->json('data.cycle'))->toBe('yearly')
        ->and($reponse->json('data.cycle_label'))->toBe('Annuel');
});

it('crédite les mois de la durée à l’approbation', function () {
    $demandeId = $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'basic', 'cycle' => 'quarterly'],
    )->json('data.id');

    $admin = User::factory()->create(['role' => 'system_admin']);
    Sanctum::actingAs($admin);

    $this->postJson("/api/admin/subscription-requests/{$demandeId}/approve")
        ->assertOk();

    $abonnement = $this->business->fresh()->subscription;

    expect($abonnement->plan)->toBe('basic')
        // Trois mois pleins : environ 91 jours.
        ->and(now()->diffInDays($abonnement->ends_at))->toBeGreaterThan(85);
});

it('refuse une durée que l’exploitant a fermée', function () {
    $basic = SubscriptionPlan::where('code', 'basic')->firstOrFail();
    $basic->prices()->where('cycle', 'biannual')->update(['is_active' => false]);

    $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'basic', 'cycle' => 'biannual'],
    )->assertStatus(422)->assertJsonValidationErrors('cycle');
});

it('refuse un plan que l’exploitant a retiré', function () {
    SubscriptionPlan::where('code', 'pro')->update(['is_active' => false]);

    $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'pro'],
    )->assertStatus(422)->assertJsonValidationErrors('plan');
});

it('ne propose pas de payer le plan gratuit', function () {
    $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'free'],
    )->assertStatus(422)->assertJsonValidationErrors('plan');
});

it('retient le mensuel quand aucune durée n’est précisée', function () {
    // Les versions de l'application déjà installées n'envoient pas de durée.
    $reponse = $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'basic'],
    )->assertStatus(202);

    expect($reponse->json('data.cycle'))->toBe('monthly')
        ->and($reponse->json('data.months'))->toBe(1)
        ->and($reponse->json('data.amount_due'))->toEqual(5000);
});

it('décrit chaque durée du catalogue', function () {
    foreach (BillingCycle::cases() as $cycle) {
        expect($cycle->label())->not->toBe('')
            ->and($cycle->unit())->not->toBe('')
            ->and($cycle->months())->toBeGreaterThan(0);
    }

    expect(BillingCycle::Quarterly->months())->toBe(3)
        ->and(BillingCycle::Biannual->months())->toBe(6)
        ->and(BillingCycle::Yearly->months())->toBe(12);
});

it('garde le paiement en ligne des abonnements en pause', function () {
    // Faute de clés de production : une souscription lancée sans elles
    // échouerait chez le commerçant. Le code reste, l'interrupteur est ouvert.
    expect(config('services.paydunya.subscriptions_enabled'))->toBeFalsy();
});
