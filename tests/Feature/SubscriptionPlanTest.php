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
 * Dépose une demande, la fait approuver, et rend la date de fin accordée.
 */
function approuver($test, string $plan, string $cycle): \Carbon\Carbon
{
    $demandeId = $test->postJson(
        "/api/businesses/{$test->business->id}/subscription/requests",
        ['plan' => $plan, 'cycle' => $cycle],
    )->json('data.id');

    $admin = User::factory()->create(['role' => 'system_admin']);
    Sanctum::actingAs($admin);

    $test->postJson("/api/admin/subscription-requests/{$demandeId}/approve")
        ->assertOk();

    Sanctum::actingAs($test->owner);

    return $test->business->fresh()->subscription->ends_at;
}

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

it('applique le quota de paiements en ligne que porte le plan', function () {
    // Le quota était écrit dans le contrôleur — « Basic → 5 » — tandis que la
    // description du plan vit en base : les deux pouvaient se contredire.
    $plan = SubscriptionPlan::where('code', 'basic')->firstOrFail();

    expect($plan->monthly_online_payments)->toBe(5)
        ->and($plan->allowsOnlinePayment(4))->toBeTrue()
        ->and($plan->allowsOnlinePayment(5))->toBeFalse();

    // Ajusté depuis la console, il vaut aussitôt.
    $plan->update(['monthly_online_payments' => 10]);

    expect($plan->fresh()->allowsOnlinePayment(5))->toBeTrue();

    // `null` vaut « sans limite » : c'est le cas de Pro.
    $pro = SubscriptionPlan::where('code', 'pro')->firstOrFail();

    expect($pro->monthly_online_payments)->toBeNull()
        ->and($pro->allowsOnlinePayment(9999))->toBeTrue();
});

it('prend la durée de l’essai sur le plan gratuit', function () {
    // Elle était écrite en dur à trois endroits : la changer depuis la console
    // n'avait aucun effet.
    $free = SubscriptionPlan::where('code', 'free')->firstOrFail();
    $free->update(['trial_days' => 14]);

    $business = Business::create([
        'owner_id' => $this->owner->id,
        'name' => 'Nouveau business',
        'type' => 'shop',
        'currency' => 'XOF',
    ]);
    $business->staff()->attach($this->owner->id, ['role' => 'manager']);

    // 201 : l'abonnement d'essai est créé à la première consultation.
    $reponse = $this->getJson("/api/businesses/{$business->id}/subscription")
        ->assertSuccessful();

    $fin = \Carbon\Carbon::parse($reponse->json('data.ends_at'));

    expect(now()->diffInDays($fin))->toBeGreaterThan(12)
        ->and(now()->diffInDays($fin))->toBeLessThan(15);
});

it('transmet la durée par l’ancien chemin de souscription', function () {
    // `POST /subscription` la perdait : toute demande valait un mois, même celle
    // d'un commerçant qui réglait son année.
    $reponse = $this->postJson("/api/businesses/{$this->business->id}/subscription", [
        'plan' => 'pro',
        'method' => 'cash',
        'cycle' => 'yearly',
        'starts_at' => now()->toDateString(),
    ])->assertStatus(202);

    expect($reponse->json('request.cycle'))->toBe('yearly')
        ->and($reponse->json('request.months'))->toBe(12)
        ->and($reponse->json('request.amount_due'))->toEqual(180000);
});

it('solde la demande en attente quand le plan est accordé à la main', function () {
    // L'exploitant peut attribuer un plan depuis la page « Abonnements » : ce
    // chemin ne touchait pas à la demande, qui restait « en attente »
    // indéfiniment alors que le commerçant avait bien son abonnement.
    $demandeId = $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'pro', 'cycle' => 'quarterly'],
    )->json('data.id');

    $admin = User::factory()->create(['role' => 'system_admin']);
    Sanctum::actingAs($admin);

    $this->postJson("/api/admin/businesses/{$this->business->id}/subscription", [
        'plan' => 'pro',
        'lifetime' => true,
    ])->assertSuccessful();

    $this->assertDatabaseHas('subscription_requests', [
        'id' => $demandeId,
        'status' => 'approved',
    ]);
});

it('prolonge le même plan en ajoutant les mois à ce qui reste', function () {
    // Un commerçant sur Basic doit pouvoir prendre un trimestre de plus sans
    // perdre les jours qui lui restent — sinon renouveler tôt le pénalise.
    $this->business->subscription->update([
        'plan' => 'basic',
        'starts_at' => now()->subDays(20),
        'ends_at' => now()->addDays(10),
        'is_active' => true,
    ]);

    $demandeId = $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'basic', 'cycle' => 'quarterly'],
    )->assertStatus(202)->json('data.id');

    $admin = User::factory()->create(['role' => 'system_admin']);
    Sanctum::actingAs($admin);

    $this->postJson("/api/admin/subscription-requests/{$demandeId}/approve")
        ->assertOk();

    // 10 jours restants + 3 mois ≈ 100 jours.
    $fin = $this->business->fresh()->subscription->ends_at;

    expect(now()->diffInDays($fin))->toBeGreaterThan(98)
        ->and(now()->diffInDays($fin))->toBeLessThan(106);
});

/**
 * Conversion du temps restant lors d'un changement de plan.
 *
 * Le jeter vole le commerçant, le reporter tel quel le fait payer trop : trente
 * jours de Basic ne valent pas trente jours de Pro. Ce qui reste est donc compté
 * en valeur, au tarif mensuel, et reconverti en jours du plan demandé.
 */
it('convertit les jours restants à la valeur du plan demandé, à la hausse', function () {
    // 30 jours de Basic (5 000/mois) valent 10 jours de Pro (15 000/mois).
    $this->business->subscription->update([
        'plan' => 'basic',
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->addDays(30),
        'is_active' => true,
        'is_manual' => false,
    ]);

    $fin = approuver($this, 'pro', 'monthly');

    // 1 mois acheté + 10 jours convertis ≈ 41 jours.
    expect(now()->diffInDays($fin))->toBeGreaterThan(38)
        ->and(now()->diffInDays($fin))->toBeLessThan(44);
});

it('convertit les jours restants à la valeur du plan demandé, à la baisse', function () {
    // 30 jours de Pro (15 000/mois) valent 90 jours de Basic (5 000/mois) : une
    // descente en gamme ne fait pas perdre ce qui a été payé.
    $this->business->subscription->update([
        'plan' => 'pro',
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->addDays(30),
        'is_active' => true,
        'is_manual' => false,
    ]);

    $fin = approuver($this, 'basic', 'monthly');

    // 1 mois acheté + 90 jours convertis ≈ 121 jours.
    expect(now()->diffInDays($fin))->toBeGreaterThan(115)
        ->and(now()->diffInDays($fin))->toBeLessThan(126);
});

it('ne convertit rien depuis un essai gratuit', function () {
    // Un essai n'a rien coûté : il n'y a pas de valeur à reporter.
    $this->business->subscription->update([
        'plan' => 'free',
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->addDays(5),
        'is_active' => true,
    ]);

    $fin = approuver($this, 'basic', 'monthly');

    expect(now()->diffInDays($fin))->toBeLessThan(33);
});

it('ne convertit rien depuis une attribution manuelle', function () {
    // Une faveur ne se convertit pas en jours payants : un cadeau de deux ans
    // multiplierait sinon la valeur accordée.
    $this->business->subscription->update([
        'plan' => 'pro',
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->addDays(700),
        'is_active' => true,
        'is_manual' => true,
    ]);

    $fin = approuver($this, 'basic', 'monthly');

    expect(now()->diffInDays($fin))->toBeLessThan(33);
});

it('ne vend pas de temps à un abonnement sans échéance', function () {
    // Il en a déjà sans limite : prendre son argent serait le prendre pour rien.
    $this->business->subscription->update([
        'plan' => 'pro',
        'starts_at' => now()->subMonth(),
        'ends_at' => null,
        'is_active' => true,
        'is_manual' => true,
    ]);

    $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'pro', 'cycle' => 'monthly'],
    )->assertStatus(422)->assertJsonValidationErrors('plan');
});

it('annonce la date que l’approbation accorderait', function () {
    // Le commerçant comme l'exploitant doivent la voir avant de décider.
    $this->business->subscription->update([
        'plan' => 'basic',
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->addDays(30),
        'is_active' => true,
        'is_manual' => false,
    ]);

    $reponse = $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'basic', 'cycle' => 'quarterly'],
    )->assertStatus(202);

    $annoncee = \Carbon\Carbon::parse($reponse->json('data.projected_ends_at'));

    // Même plan : 30 jours restants + 3 mois ≈ 121 jours.
    expect(now()->diffInDays($annoncee))->toBeGreaterThan(115)
        ->and(now()->diffInDays($annoncee))->toBeLessThan(126);
});

it('laisse déposer une autre demande après avoir retiré la précédente', function () {
    // Une seule demande en attente à la fois : la retirer est ce qui permet
    // d'en déposer une autre, pour une autre durée par exemple.
    $premiere = $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'basic', 'cycle' => 'monthly'],
    )->json('data.id');

    $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'basic', 'cycle' => 'yearly'],
    )->assertStatus(422);

    $this->deleteJson(
        "/api/businesses/{$this->business->id}/subscription/requests/{$premiere}",
    )->assertOk();

    $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'basic', 'cycle' => 'yearly'],
    )->assertStatus(202)->assertJsonPath('data.cycle', 'yearly');
});

it('liste ses demandes au commerçant', function () {
    // L'application s'en sert pour dire « demande en attente » avant de laisser
    // déposer, plutôt que de laisser le serveur refuser après coup.
    $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'pro', 'cycle' => 'biannual', 'method' => 'cash'],
    )->assertStatus(202);

    $demandes = $this->getJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
    )->assertOk()->json('data');

    expect($demandes)->toHaveCount(1)
        ->and($demandes[0]['status'])->toBe('pending')
        ->and($demandes[0]['cycle_label'])->toBe('Semestriel')
        ->and($demandes[0]['months'])->toBe(6);
});

it('annonce l’aperçu avant que la demande ne soit déposée', function () {
    // Le commerçant doit voir qu'il ne perd rien **avant** de demander.
    $this->business->subscription->update([
        'plan' => 'basic',
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->addDays(20),
        'is_active' => true,
        'is_manual' => false,
    ]);

    // Même plan : les 20 jours sont conservés tels quels.
    $apercu = $this->getJson(
        "/api/businesses/{$this->business->id}/subscription/preview?plan=basic&cycle=quarterly",
    )->assertOk()->json('data');

    expect($apercu['remaining_days'])->toBe(20)
        ->and($apercu['extends'])->toBeTrue()
        ->and($apercu['credited_days'])->toBe(20);

    $fin = \Carbon\Carbon::parse($apercu['ends_at']);
    expect(now()->diffInDays($fin))->toBeGreaterThan(105);

    // Changement de plan : les 20 jours de Basic valent moins en Pro.
    $versPro = $this->getJson(
        "/api/businesses/{$this->business->id}/subscription/preview?plan=pro&cycle=monthly",
    )->assertOk()->json('data');

    expect($versPro['extends'])->toBeFalse()
        ->and($versPro['credited_days'])->toBeLessThan(20)
        ->and($versPro['credited_days'])->toBeGreaterThan(0);
});
