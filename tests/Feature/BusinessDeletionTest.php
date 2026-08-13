<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * La suppression d'un business est une désactivation logique (is_active = false),
 * pas un hard delete. Ces tests verrouillent le fait qu'un business désactivé
 * disparaît bien de la liste de l'owner et n'y revient jamais.
 */
beforeEach(function () {
    $this->owner = User::factory()->create(['role' => 'owner']);
    Sanctum::actingAs($this->owner);
});

test('supprimer un business le fait disparaître de la liste', function () {
    $business = Business::create([
        'owner_id' => $this->owner->id,
        'name' => 'Boutique Test',
        'type' => 'shop',
        'is_active' => true,
    ]);

    $before = $this->getJson('/api/businesses');
    expect(collect($before->json('data'))->pluck('id'))->toContain($business->id);

    $this->deleteJson("/api/businesses/{$business->id}")->assertSuccessful();

    $after = $this->getJson('/api/businesses');
    expect(collect($after->json('data'))->pluck('id'))->not->toContain($business->id);

    expect($business->fresh()->is_active)->toBeFalse();
});

test('un business désactivé ne revient jamais dans la liste après rechargement', function () {
    Business::create([
        'owner_id' => $this->owner->id,
        'name' => 'Ancien business',
        'type' => 'shop',
        'is_active' => false,
    ]);

    $active = Business::create([
        'owner_id' => $this->owner->id,
        'name' => 'Business actif',
        'type' => 'shop',
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/businesses');

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($active->id)
        ->and($ids)->toHaveCount(1);
});
