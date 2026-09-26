<?php

use App\Mail\SubscriptionRequestedMail;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Il n'y a pas de palier gratuit : après l'essai, soit un abonnement en cours,
 * soit aucun encaissement — espèces comprises.
 */
beforeEach(function () {
    $this->owner = User::factory()->create(['role' => 'owner', 'phone' => '+22373136789']);

    $this->business = Business::create([
        'owner_id' => $this->owner->id,
        'name' => 'Boutique Test',
        'type' => 'shop',
        'currency' => 'XOF',
    ]);

    Sanctum::actingAs($this->owner);
});

function abonner(string $plan, $startsAt, $endsAt, bool $manual = false): Subscription
{
    return Subscription::create([
        'business_id' => test()->business->id,
        'plan' => $plan,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'is_active' => true,
        'is_manual' => $manual,
    ]);
}

function encaisserAvec(string $method = 'cash')
{
    return test()->postJson('/api/businesses/'.test()->business->id.'/payments', [
        'phone' => '70000001',
        'name' => 'Client Test',
        'amount' => 1000,
        'method' => $method,
    ]);
}

it('laisse encaisser pendant l’essai, tous moyens enregistrés comme des espèces', function (string $method) {
    abonner('trial', now()->subDays(2), now()->addDays(5));

    encaisserAvec($method)->assertSuccessful();

    $payment = Payment::first();
    expect($payment->status)->toBe('success')
        ->and($payment->provider)->toBeNull()
        ->and($payment->paid_at)->not->toBeNull();
})->with(['cash', 'orange_money', 'wave', 'moov_money']);

it('bloque tout encaissement après l’essai, espèces comprises', function (string $method) {
    abonner('trial', now()->subDays(10), now()->subDays(3));

    encaisserAvec($method)->assertForbidden()->assertJson(['code' => 'SUBSCRIPTION_EXPIRED']);

    expect(Payment::count())->toBe(0);
})->with(['cash', 'orange_money', 'wave', 'moov_money']);

it('bloque l’encaissement d’un plan payant expiré, sans le rétrograder', function () {
    $sub = abonner('basic', now()->subMonths(2), now()->subDay());

    encaisserAvec()->assertForbidden()->assertJson(['code' => 'SUBSCRIPTION_EXPIRED']);

    expect($sub->fresh()->plan)->toBe('basic')
        ->and(Payment::count())->toBe(0);
});

it('laisse encaisser jusqu’à la fin du dernier jour', function () {
    abonner('pro', now()->subMonth(), now());

    encaisserAvec()->assertSuccessful();
});

it('bloque l’encaissement sans aucun abonnement', function () {
    encaisserAvec()->assertForbidden()->assertJson(['code' => 'SUBSCRIPTION_EXPIRED']);
});

it('laisse encaisser un plan accordé à vie', function () {
    abonner('pro', now()->subYear(), null, manual: true);

    encaisserAvec()->assertSuccessful();
});

it('bloque l’encaissement dès la révocation', function () {
    $sub = abonner('pro', now()->subYear(), null, manual: true);

    $sub->expireNow();

    encaisserAvec()->assertForbidden();
});

it('démarre l’essai à la première consultation, sans jamais le relancer', function () {
    $this->getJson('/api/businesses/'.$this->business->id.'/subscription')
        ->assertSuccessful()
        ->assertJsonPath('data.plan', 'trial')
        ->assertJsonPath('data.is_currently_active', true);

    $sub = Subscription::first();
    $sub->update(['starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(3)]);

    $this->getJson('/api/businesses/'.$this->business->id.'/subscription')
        ->assertSuccessful()
        ->assertJsonPath('data.plan', 'trial')
        ->assertJsonPath('data.is_currently_active', false);

    expect(Subscription::count())->toBe(1);
});

it('ne connaît plus de plan gratuit à souscrire', function (string $plan) {
    abonner('trial', now()->subDays(10), now()->subDays(3));

    $this->postJson('/api/businesses/'.$this->business->id.'/subscription', ['plan' => $plan])
        ->assertStatus(422)->assertJsonValidationErrors('plan');

    expect($this->business->fresh()->subscription->isCurrentlyActive())->toBeFalse();
})->with(['free', 'trial']);

it('prévient l’exploitant par mail à chaque demande, sans activer le plan', function () {
    Mail::fake();
    abonner('trial', now()->subDays(10), now()->subDays(3));

    $this->postJson('/api/businesses/'.$this->business->id.'/subscription', [
        'plan' => 'pro',
        'method' => 'orange_money',
    ])->assertStatus(202)->assertJsonPath('code', 'SUBSCRIPTION_PENDING_VALIDATION');

    Mail::assertSent(SubscriptionRequestedMail::class, fn ($mail) =>
        $mail->hasTo('ismalsacko@yahoo.fr')
        && $mail->subscriptionRequest->plan === 'pro'
        && $mail->subscriptionRequest->business->is($this->business));

    expect($this->business->fresh()->subscription->isCurrentlyActive())->toBeFalse();
});

it('garde la demande même si le mail ne part pas', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP indisponible'));
    abonner('trial', now()->subDays(10), now()->subDays(3));

    $this->postJson('/api/businesses/'.$this->business->id.'/subscription/requests', ['plan' => 'basic'])
        ->assertStatus(202);

    expect(SubscriptionRequest::count())->toBe(1);
});

it('met dans le mail le montant, le téléphone et le lien WhatsApp', function () {
    abonner('trial', now()->subDays(10), now()->subDays(3));

    $this->postJson('/api/businesses/'.$this->business->id.'/subscription/requests', [
        'plan' => 'basic',
    ])->assertStatus(202);

    $html = (new SubscriptionRequestedMail(SubscriptionRequest::firstOrFail()))->render();

    expect($html)->toContain('Boutique Test')
        ->toContain('5 000 XOF')
        ->toContain('+22373136789')
        ->toContain('https://wa.me/22373136789')
        ->toContain('expiré le');
});

it('n’expose plus le rappel PayDunya', function () {
    $this->postJson('/api/payments/callback', ['status' => 'completed'])->assertNotFound();
});
