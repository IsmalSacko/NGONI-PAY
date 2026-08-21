<?php

use App\Models\AppNotification;
use App\Models\Business;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Les décisions de l'exploitant ne parvenaient jamais à l'intéressé : il devait
 * rouvrir l'application et deviner. L'application tenait bien une liste de
 * notifications, mais purement locale — elle n'y inscrivait que ce qu'elle
 * faisait elle-même.
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
        'ends_at' => now()->addDays(5),
        'is_active' => true,
    ]);

    $this->admin = User::factory()->create(['role' => 'system_admin']);
});

it('prévient le commerçant quand sa demande est approuvée', function () {
    Sanctum::actingAs($this->owner);

    $demandeId = $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'pro', 'cycle' => 'quarterly'],
    )->json('data.id');

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/admin/subscription-requests/{$demandeId}/approve")
        ->assertOk();

    $notification = AppNotification::where('user_id', $this->owner->id)->first();

    expect($notification)->not->toBeNull()
        ->and($notification->type)->toBe('subscription.approved')
        ->and($notification->title)->toBe('Abonnement activé')
        // La date accordée figure dans le message : c'est ce que le commerçant
        // veut savoir.
        ->and($notification->body)->toContain('PRO');
});

it('prévient le commerçant d’un refus, avec le motif', function () {
    Sanctum::actingAs($this->owner);

    $demandeId = $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'basic'],
    )->json('data.id');

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/admin/subscription-requests/{$demandeId}/refuse", [
        'reason' => 'Aucun paiement reçu',
    ])->assertOk();

    $notification = AppNotification::where('user_id', $this->owner->id)->first();

    expect($notification->type)->toBe('subscription.refused')
        // Sans le motif, le commerçant ne sait pas quoi corriger.
        ->and($notification->body)->toContain('Aucun paiement reçu');
});

it('prévient le commerçant d’une attribution manuelle', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson(
        "/api/businesses/{$this->business->id}/subscription/requests",
        ['plan' => 'pro'],
    )->assertStatus(202);

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/admin/businesses/{$this->business->id}/subscription", [
        'plan' => 'pro',
        'lifetime' => true,
    ])->assertSuccessful();

    expect(AppNotification::where('user_id', $this->owner->id)
        ->where('type', 'subscription.granted')
        ->exists())->toBeTrue();
});

it('sert ses notifications au commerçant, non lues comptées', function () {
    AppNotification::create([
        'user_id' => $this->owner->id,
        'business_id' => $this->business->id,
        'type' => 'subscription.approved',
        'title' => 'Abonnement activé',
        'body' => 'Votre plan PRO est actif.',
        'route' => '/subscription/' . $this->business->id,
    ]);

    Sanctum::actingAs($this->owner);

    $reponse = $this->getJson('/api/notifications')->assertOk();

    expect($reponse->json('unread_count'))->toBe(1)
        ->and($reponse->json('data.0.title'))->toBe('Abonnement activé')
        ->and($reponse->json('data.0.is_read'))->toBeFalse();
});

it('marque une notification comme lue', function () {
    $notification = AppNotification::create([
        'user_id' => $this->owner->id,
        'type' => 'account.reactivated',
        'title' => 'Compte réactivé',
        'body' => 'Votre compte est de nouveau actif.',
    ]);

    Sanctum::actingAs($this->owner);

    $this->postJson("/api/notifications/{$notification->id}/read")->assertOk();

    expect($notification->fresh()->read_at)->not->toBeNull();

    $this->getJson('/api/notifications')->assertOk()
        ->assertJsonPath('unread_count', 0);
});

it('ne laisse pas lire les notifications d’un autre', function () {
    $notification = AppNotification::create([
        'user_id' => $this->owner->id,
        'type' => 'account.reactivated',
        'title' => 'Compte réactivé',
        'body' => 'Votre compte est de nouveau actif.',
    ]);

    $autre = User::factory()->create(['role' => 'owner']);
    Sanctum::actingAs($autre);

    $this->postJson("/api/notifications/{$notification->id}/read")
        ->assertNotFound();

    $this->getJson('/api/notifications')->assertOk()
        ->assertJsonPath('unread_count', 0)
        ->assertJsonCount(0, 'data');
});
