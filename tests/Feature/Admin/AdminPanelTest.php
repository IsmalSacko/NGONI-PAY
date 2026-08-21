<?php

use App\Livewire\Admin\Businesses\Show as BusinessesShow;
use App\Livewire\Admin\Users\Index as UsersIndex;
use App\Models\Business;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeAdmin(): User
{
    return User::create([
        'name' => 'Admin',
        'phone' => '+22370000000',
        'password' => Hash::make('secret'),
        'role' => User::ROLE_SYSTEM_ADMIN,
        'is_active' => true,
    ]);
}

function makeBusinessWithOwner(): Business
{
    $owner = User::create([
        'name' => 'Owner',
        'phone' => '+22371111111',
        'password' => Hash::make('secret'),
        'role' => User::ROLE_OWNER,
        'is_active' => true,
    ]);

    return Business::create([
        'owner_id' => $owner->id,
        'name' => 'Test Shop',
        'type' => 'shop',
        'is_active' => true,
    ]);
}

test('un system_admin peut désactiver puis réactiver une entreprise', function () {
    $admin = makeAdmin();
    $business = makeBusinessWithOwner();

    Livewire::actingAs($admin)
        ->test(BusinessesShow::class, ['business' => $business])
        ->call('toggleActive');

    expect($business->fresh()->is_active)->toBeFalse();
});

test('un system_admin peut supprimer définitivement une entreprise et ses données liées', function () {
    $admin = makeAdmin();
    $business = makeBusinessWithOwner();

    Subscription::create([
        'business_id' => $business->id,
        'plan' => 'pro',
        'starts_at' => now(),
        'ends_at' => null,
        'is_active' => true,
    ]);

    $client = Client::create([
        'business_id' => $business->id,
        'name' => 'Client Test',
        'phone' => '+22374444444',
    ]);

    Payment::create([
        'business_id' => $business->id,
        'client_id' => $client->id,
        'user_id' => $admin->id,
        'amount' => 1000,
        'currency' => 'XOF',
        'method' => 'cash',
        'status' => 'success',
        'transaction_ref' => 'ref-force-delete-test',
    ]);

    Livewire::actingAs($admin)
        ->test(BusinessesShow::class, ['business' => $business])
        ->call('forceDelete')
        ->assertRedirect(route('admin.businesses.index'));

    expect(Business::find($business->id))->toBeNull()
        ->and(Subscription::where('business_id', $business->id)->exists())->toBeFalse()
        ->and(Client::where('business_id', $business->id)->exists())->toBeFalse()
        ->and(Payment::where('business_id', $business->id)->exists())->toBeFalse();
});

test('grantSubscription force un plan manuel identique à AdminSubscriptionController::grant', function () {
    $admin = makeAdmin();
    $business = makeBusinessWithOwner();

    Livewire::actingAs($admin)
        ->test(BusinessesShow::class, ['business' => $business])
        ->set('plan', 'pro')
        ->set('lifetime', true)
        ->call('grantSubscription');

    $subscription = $business->fresh()->subscription;

    expect($subscription->plan)->toBe('pro')
        ->and($subscription->is_manual)->toBeTrue()
        ->and($subscription->ends_at)->toBeNull()
        ->and($subscription->granted_by)->toBe($admin->id);
});

test('revokeSubscription repasse en free et retire is_manual', function () {
    $admin = makeAdmin();
    $business = makeBusinessWithOwner();

    Subscription::create([
        'business_id' => $business->id,
        'plan' => 'pro',
        'starts_at' => now(),
        'ends_at' => null,
        'is_active' => true,
        'is_manual' => true,
        'granted_by' => $admin->id,
    ]);

    Livewire::actingAs($admin)
        ->test(BusinessesShow::class, ['business' => $business])
        ->call('revokeSubscription');

    $subscription = $business->fresh()->subscription;

    expect($subscription->plan)->toBe('free')
        ->and($subscription->is_manual)->toBeFalse()
        ->and($subscription->granted_by)->toBeNull();
});

test('un system_admin ne peut pas se supprimer lui-même depuis le panneau', function () {
    $admin = makeAdmin();

    Livewire::actingAs($admin)
        ->test(UsersIndex::class)
        ->call('delete', $admin->id);

    expect(User::find($admin->id))->not->toBeNull();
});

test('un system_admin ne peut pas supprimer un autre system_admin depuis le panneau', function () {
    $admin = makeAdmin();
    $otherAdmin = User::create([
        'name' => 'Autre Admin',
        'phone' => '+22372222222',
        'password' => Hash::make('secret'),
        'role' => User::ROLE_SYSTEM_ADMIN,
        'is_active' => true,
    ]);

    Livewire::actingAs($admin)
        ->test(UsersIndex::class)
        ->call('delete', $otherAdmin->id);

    expect(User::find($otherAdmin->id))->not->toBeNull();
});

test('un system_admin peut supprimer un utilisateur normal', function () {
    $admin = makeAdmin();
    $owner = User::create([
        'name' => 'Owner à supprimer',
        'phone' => '+22373333333',
        'password' => Hash::make('secret'),
        'role' => User::ROLE_OWNER,
        'is_active' => true,
    ]);

    Livewire::actingAs($admin)
        ->test(UsersIndex::class)
        ->call('delete', $owner->id);

    expect(User::find($owner->id))->toBeNull();
});

/**
 * Les pages de la console se rendent-elles vraiment ?
 *
 * Un composant Livewire qui ne déclare pas son gabarit lève « No hint path
 * defined for [layouts] » : la classe passe l'analyse, les tests unitaires ne la
 * touchent pas, et la page renvoie 500 en production. Ces tests visitent les
 * routes pour de bon.
 */
test('les pages de la console se rendent pour un system_admin', function () {
    $admin = makeAdmin();
    makeBusinessWithOwner();

    $routes = [
        'admin.dashboard',
        'admin.users.index',
        'admin.businesses.index',
        'admin.subscriptions.index',
        'admin.subscription-requests.index',
        'admin.plans.index',
        'admin.payments.index',
        'admin.campaigns.index',
    ];

    foreach ($routes as $route) {
        $this->actingAs($admin)
            ->get(route($route))
            ->assertOk();
    }
});

test('la console est fermée à un commerçant', function () {
    $business = makeBusinessWithOwner();

    $this->actingAs($business->owner)
        ->get(route('admin.subscription-requests.index'))
        ->assertForbidden();
});

test('la console signale les demandes à instruire', function () {
    // Rien ne les signalait : il fallait ouvrir la page pour savoir qu'un
    // commerçant attendait.
    $admin = makeAdmin();
    $business = makeBusinessWithOwner();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Aucune demande en attente');

    \App\Models\SubscriptionRequest::create([
        'business_id' => $business->id,
        'requested_by_user_id' => $business->owner_id,
        'plan' => 'pro',
        'amount_due' => 15000,
        'currency' => 'XOF',
        'months' => 1,
        'cycle' => 'monthly',
        'status' => 'pending',
    ]);

    // Le compteur se lit depuis n'importe quelle page de la console.
    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('1 demande(s) en attente');
});
