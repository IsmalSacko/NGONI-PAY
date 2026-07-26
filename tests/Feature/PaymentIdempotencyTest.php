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
 * L'application mobile enregistre des encaissements hors ligne puis les rejoue
 * au retour du réseau. Elle rejoue aussi une requête dont la réponse s'est
 * perdue (timeout). Ces tests verrouillent le fait qu'un rejeu ne crée jamais
 * un second paiement.
 */
beforeEach(function () {
    $this->owner = User::factory()->create(['role' => 'owner']);

    $this->business = Business::create([
        'owner_id' => $this->owner->id,
        'name' => 'Boutique Test',
        'type' => 'shop',
    ]);

    Subscription::create([
        'business_id' => $this->business->id,
        'plan' => 'pro',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
        'is_active' => true,
    ]);

    Sanctum::actingAs($this->owner);
});

function encaisser(array $payload = [], ?string $key = null)
{
    $payload = array_merge([
        'phone' => '70000001',
        'name' => 'Client Test',
        'amount' => 1000,
        'method' => 'cash',
    ], $payload);

    return test()->postJson(
        '/api/businesses/'.test()->business->id.'/payments',
        $payload,
        $key !== null ? ['Idempotency-Key' => $key] : []
    );
}

test('un rejeu avec la même clé ne crée pas un second paiement', function () {
    $first = encaisser(key: 'np-abc-123');
    $first->assertSuccessful();

    // Le mobile n'a jamais reçu la réponse et rejoue exactement la même requête.
    $replay = encaisser(key: 'np-abc-123');
    $replay->assertSuccessful();

    expect(Payment::count())->toBe(1);
    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and($replay->json('data.transaction_ref'))
        ->toBe($first->json('data.transaction_ref'));
});

test('deux encaissements distincts ont deux clés distinctes et sont tous créés', function () {
    encaisser(key: 'np-un')->assertSuccessful();
    encaisser(key: 'np-deux')->assertSuccessful();

    expect(Payment::count())->toBe(2);
});

test('deux encaissements identiques ne sont pas confondus', function () {
    // Même client, même montant, deux ventes réelles : rien ne doit être perdu.
    encaisser(['amount' => 500], key: 'np-vente-1')->assertSuccessful();
    encaisser(['amount' => 500], key: 'np-vente-2')->assertSuccessful();

    expect(Payment::count())->toBe(2);
});

test('la clé est conservée en base pour permettre la déduplication', function () {
    encaisser(key: 'np-persistee')->assertSuccessful();

    expect(Payment::first()->idempotency_key)->toBe('np-persistee');
});

test('sans clé, le comportement reste inchangé', function () {
    encaisser()->assertSuccessful();
    encaisser()->assertSuccessful();

    // Aucune déduplication implicite : deux requêtes sans clé = deux paiements.
    expect(Payment::count())->toBe(2);
    expect(Payment::whereNotNull('idempotency_key')->count())->toBe(0);
});

test('la même clé sur deux entreprises différentes reste valide', function () {
    $autre = Business::create([
        'owner_id' => $this->owner->id,
        'name' => 'Boutique 2',
        'type' => 'shop',
    ]);
    Subscription::create([
        'business_id' => $autre->id,
        'plan' => 'pro',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
        'is_active' => true,
    ]);

    encaisser(key: 'np-partagee')->assertSuccessful();

    $this->postJson("/api/businesses/{$autre->id}/payments", [
        'phone' => '70000001',
        'amount' => 1000,
        'method' => 'cash',
    ], ['Idempotency-Key' => 'np-partagee'])->assertSuccessful();

    expect(Payment::count())->toBe(2);
});

test('un rejeu n’est pas refusé par le quota mensuel du plan basic', function () {
    // Le plan basic limite les paiements en ligne à 5 par mois. Un rejeu porte
    // sur un paiement déjà compté : il doit renvoyer l'existant, pas un 403.
    Subscription::where('business_id', $this->business->id)->update(['plan' => 'basic']);

    for ($i = 0; $i < 5; $i++) {
        Payment::create([
            'business_id' => $this->business->id,
            'client_id' => Client::create([
                'business_id' => $this->business->id,
                'phone' => '7100000'.$i,
                'name' => 'C'.$i,
            ])->id,
            'user_id' => $this->owner->id,
            'amount' => 100,
            'currency' => 'XOF',
            'method' => 'wave',
            'provider' => 'paydunya',
            'purpose' => 'sale',
            'transaction_ref' => 'TX-'.$i,
            'status' => 'success',
            'paid_at' => now(),
        ]);
    }

    // Un encaissement espèces déjà enregistré avec sa clé.
    $first = encaisser(key: 'np-quota')->assertSuccessful();

    // Rejeu : doit renvoyer l'existant.
    $replay = encaisser(key: 'np-quota');
    $replay->assertSuccessful();
    expect($replay->json('data.id'))->toBe($first->json('data.id'));
});

test('une clé trop longue est refusée plutôt que tronquée', function () {
    encaisser(key: str_repeat('a', 129))->assertStatus(422);

    expect(Payment::count())->toBe(0);
});

test('un rejeu ne contourne pas le contrôle d’accès', function () {
    $first = encaisser(key: 'np-secret')->assertSuccessful();

    // Un utilisateur étranger à l'entreprise ne doit rien pouvoir en tirer,
    // même en connaissant la clé.
    Sanctum::actingAs(User::factory()->create(['role' => 'owner']));

    encaisser(key: 'np-secret')->assertStatus(403);
    expect(Payment::count())->toBe(1);
});
