<?php

use App\Enums\BusinessType;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Cinq secteurs étaient proposés. Un menuisier, un bijoutier ou un coiffeur
 * n'avait que « service » pour se décrire — ni sur sa facture, ni ailleurs.
 */
it('décrit chaque secteur du catalogue', function () {
    foreach (BusinessType::cases() as $type) {
        expect($type->label())->not->toBe('')
            ->and($type->family())->not->toBe('');
    }

    expect(BusinessType::cases())->toHaveCount(45);
});

it('garde les cinq secteurs d’origine, présents en base', function () {
    foreach (['shop', 'school', 'pharmacy', 'garage', 'service'] as $ancien) {
        expect(BusinessType::tryFrom($ancien))->not->toBeNull();
    }
});

it('accepte un secteur qui n’était pas proposé avant', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    Sanctum::actingAs($owner);

    $this->postJson('/api/businesses', [
        'name' => 'Menuiserie Sanogo',
        'type' => 'carpentry',
        'phone' => '76008201',
    ])->assertSuccessful()
        ->assertJsonPath('data.type', 'carpentry')
        ->assertJsonPath('data.type_label', 'Menuiserie');
});

it('refuse un secteur inventé', function () {
    // La colonne n'est plus un `enum` : sans validation applicative, une chaîne
    // inventée s'afficherait telle quelle sur les factures.
    $owner = User::factory()->create(['role' => 'owner']);
    Sanctum::actingAs($owner);

    $this->postJson('/api/businesses', [
        'name' => 'Test',
        'type' => 'licorne',
        'phone' => '76008201',
    ])->assertStatus(422)->assertJsonValidationErrors('type');
});

it('rend lisible un secteur hérité que le catalogue ignore', function () {
    // Une facture qui annonce « Autre activité » est moins fausse qu'une qui
    // annonce « Boutique ».
    expect(BusinessType::labelFor('valeur_inconnue'))->toBe('Autre activité')
        ->and(BusinessType::labelFor(null))->toBe('Autre activité');
});

it('joint au reçu l’identité complète du service qui l’émet', function () {
    // Le nom seul était transmis : le client ne savait ni où ni à qui
    // s'adresser pour contester un paiement.
    $owner = User::factory()->create(['role' => 'owner']);

    $business = Business::create([
        'owner_id' => $owner->id,
        'name' => 'Bijouterie Diallo',
        'type' => 'jewelry',
        'address' => 'Rue 224, Hamdallaye ACI 2000, Bamako',
        'phone' => '+22376008201',
        'currency' => 'XOF',
    ]);
    $business->staff()->attach($owner->id, ['role' => 'manager']);
    $business->subscription()->create([
        'plan' => 'pro',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
        'is_active' => true,
    ]);

    Sanctum::actingAs($owner);

    $paiement = $this->postJson("/api/businesses/{$business->id}/payments", [
        'phone' => '76112233',
        'name' => 'Cliente',
        'amount' => 25000,
        'method' => 'cash',
    ]);
    $paiement->assertSuccessful();

    $paymentId = $paiement->json('data.id') ?? $paiement->json('id');

    $this->getJson("/api/payments/{$paymentId}/invoice")
        ->assertOk()
        ->assertJsonPath('data.payment.business.name', 'Bijouterie Diallo')
        ->assertJsonPath('data.payment.business.type_label', 'Bijouterie')
        ->assertJsonPath('data.payment.business.address', 'Rue 224, Hamdallaye ACI 2000, Bamako')
        ->assertJsonPath('data.payment.business.phone', '+22376008201')
        ->assertJsonPath('data.payment.business.currency', 'XOF');
});
