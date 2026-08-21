<?php

use App\Models\Business;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Un abonnement se paie. Deux chemins permettaient de s'en passer :
 * - déclarer un paiement « en espèces », qui activait le plan sur-le-champ ;
 * - appeler `PUT` sur son propre abonnement, dont la requête accepte `plan`,
 *   `ends_at` et `is_active`.
 *
 * Et une fois « payé », l'abonnement venait gonfler le chiffre d'affaires du
 * commerçant : sa dépense comptait pour une recette.
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

    $this->client = Client::create([
        'business_id' => $this->business->id,
        'name' => 'Client Test',
        'phone' => '+22376112233',
    ]);

    Subscription::create([
        'business_id' => $this->business->id,
        'plan' => 'free',
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->addDays(5),
        'is_active' => true,
    ]);

    Sanctum::actingAs($this->owner);
});

it('n’active pas un plan payant sur une déclaration de paiement en espèces', function () {
    $response = $this->postJson("/api/businesses/{$this->business->id}/subscription", [
        'plan' => 'pro',
        'method' => 'cash',
        'starts_at' => now()->toDateString(),
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('code', 'SUBSCRIPTION_PENDING_VALIDATION');

    // Le plan n'a pas bougé : il s'activait pour un mois, gratuitement.
    expect($this->business->fresh()->subscription->plan)->toBe('free');
});

it('trace la demande en espèces sans l’encaisser', function () {
    $this->postJson("/api/businesses/{$this->business->id}/subscription", [
        'plan' => 'basic',
        'method' => 'cash',
        'starts_at' => now()->toDateString(),
    ])->assertStatus(202);

    // Le paiement existe — un administrateur doit pouvoir le retrouver — mais il
    // reste en attente : personne n'a constaté l'argent.
    $this->assertDatabaseHas('payments', [
        'business_id' => $this->business->id,
        'purpose' => 'subscription',
        'status' => 'pending',
        'amount' => 5000,
    ]);
});

it('refuse au propriétaire de modifier son propre abonnement', function () {
    $subscription = $this->business->subscription;

    $this->putJson(
        "/api/businesses/{$this->business->id}/subscription/{$subscription->id}",
        ['plan' => 'pro', 'is_active' => true, 'ends_at' => '2030-01-01'],
    )->assertStatus(403);

    expect($subscription->fresh()->plan)->toBe('free');
});

it('laisse un administrateur accorder un plan', function () {
    $admin = User::factory()->create(['role' => 'system_admin']);
    Sanctum::actingAs($admin);

    $subscription = $this->business->subscription;

    $this->putJson(
        "/api/businesses/{$this->business->id}/subscription/{$subscription->id}",
        ['plan' => 'pro'],
    )->assertOk();

    expect($subscription->fresh()->plan)->toBe('pro');
});

it('ne compte pas l’abonnement dans le chiffre d’affaires', function () {
    // La dépense du commerçant apparaissait comme une recette : un plan Pro à
    // 15 000 gonflait le total du jour.
    Payment::create([
        'business_id' => $this->business->id,
        'client_id' => $this->client->id,
        'user_id' => $this->owner->id,
        'amount' => 15000,
        'currency' => 'XOF',
        'method' => 'cash',
        'purpose' => 'subscription',
        'status' => 'success',
        'transaction_ref' => 'abo-1',
        'paid_at' => now(),
    ]);

    Payment::create([
        'business_id' => $this->business->id,
        'client_id' => $this->client->id,
        'user_id' => $this->owner->id,
        'amount' => 2500,
        'currency' => 'XOF',
        'method' => 'cash',
        'purpose' => 'sale',
        'status' => 'success',
        'transaction_ref' => 'vente-1',
        'paid_at' => now(),
    ]);

    $stats = $this->getJson("/api/businesses/{$this->business->id}/stats")
        ->assertOk()
        ->json('data');

    expect($stats['total_success'])->toEqual(2500)
        ->and($stats['today'])->toEqual(2500)
        ->and($stats['count_success'])->toBe(1);
});

it('n’additionne pas des devises différentes', function () {
    // 5 000 francs CFA et 22 euros ne font pas 5 022. Le business compte en
    // euros : les encaissements hérités en francs CFA sont rendus à part.
    $this->business->update(['currency' => 'EUR']);

    foreach ([['XOF', 5000, 'a'], ['XOF', 15000, 'b'], ['EUR', 22, 'c']] as [$devise, $montant, $ref]) {
        Payment::create([
            'business_id' => $this->business->id,
            'client_id' => $this->client->id,
            'user_id' => $this->owner->id,
            'amount' => $montant,
            'currency' => $devise,
            'method' => 'cash',
            'purpose' => 'sale',
            'status' => 'success',
            'transaction_ref' => $ref,
            'paid_at' => now(),
        ]);
    }

    $stats = $this->getJson("/api/businesses/{$this->business->id}/stats")
        ->assertOk()
        ->json('data');

    expect($stats['currency'])->toBe('EUR')
        ->and($stats['total_success'])->toEqual(22)
        // Rien n'est écarté en silence : ce qui n'entre pas dans le total est dit.
        ->and($stats['other_currencies'])->toHaveCount(1)
        ->and($stats['other_currencies'][0]['currency'])->toBe('XOF')
        ->and($stats['other_currencies'][0]['total'])->toEqual(20000)
        ->and($stats['other_currencies'][0]['count'])->toBe(2);
});

it('exclut les périodes des paiements non encaissés', function () {
    // `today` ne filtrait pas sur le statut : les paiements en attente, échoués
    // et annulés étaient comptés dans la recette du jour.
    foreach ([['success', 1000, 'ok'], ['pending', 9999, 'att'], ['failed', 8888, 'ech'], ['cancelled', 7777, 'ann']] as [$statut, $montant, $ref]) {
        Payment::create([
            'business_id' => $this->business->id,
            'client_id' => $this->client->id,
            'user_id' => $this->owner->id,
            'amount' => $montant,
            'currency' => 'XOF',
            'method' => 'cash',
            'purpose' => 'sale',
            'status' => $statut,
            'transaction_ref' => $ref,
        ]);
    }

    $stats = $this->getJson("/api/businesses/{$this->business->id}/stats")
        ->assertOk()
        ->json('data');

    expect($stats['today'])->toEqual(1000)
        ->and($stats['last_7_days'])->toEqual(1000)
        ->and($stats['last_30_days'])->toEqual(1000);
});

it('écarte les abonnements de la liste des paiements', function () {
    Payment::create([
        'business_id' => $this->business->id,
        'client_id' => $this->client->id,
        'user_id' => $this->owner->id,
        'amount' => 15000,
        'currency' => 'XOF',
        'method' => 'cash',
        'purpose' => 'subscription',
        'status' => 'success',
        'transaction_ref' => 'abo-2',
    ]);

    Payment::create([
        'business_id' => $this->business->id,
        'client_id' => $this->client->id,
        'user_id' => $this->owner->id,
        'amount' => 2500,
        'currency' => 'XOF',
        'method' => 'cash',
        'purpose' => 'sale',
        'status' => 'success',
        'transaction_ref' => 'vente-2',
    ]);

    $liste = $this->getJson("/api/businesses/{$this->business->id}/payments")
        ->assertOk()
        ->json('data');

    expect($liste)->toHaveCount(1)
        ->and($liste[0]['amount'])->toEqual(2500);

    // Rien ne devient inatteignable pour autant.
    $abonnements = $this->getJson(
        "/api/businesses/{$this->business->id}/payments?purpose=subscription",
    )->assertOk()->json('data');

    expect($abonnements)->toHaveCount(1);
});
