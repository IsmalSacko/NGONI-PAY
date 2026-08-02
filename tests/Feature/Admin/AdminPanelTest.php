<?php

use App\Livewire\Admin\Businesses\Show as BusinessesShow;
use App\Livewire\Admin\Users\Index as UsersIndex;
use App\Models\Business;
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
