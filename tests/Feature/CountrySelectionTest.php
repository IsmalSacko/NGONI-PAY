<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * L'application dessert plusieurs pays. Le pays du compte donne l'indicatif de
 * son numéro — donc son identifiant de connexion — et la devise dans laquelle
 * son commerce encaisse. Ces tests verrouillent les deux, et la compatibilité
 * avec les versions déjà installées qui n'envoient pas encore le pays.
 */
it('publie le catalogue des pays sans authentification', function () {
    $response = $this->getJson('/api/countries');

    $response->assertOk()
        ->assertJsonPath('default', 'ML')
        ->assertJsonStructure(['data' => [['code', 'name', 'dialingCode', 'flag', 'currency']]]);

    $codes = collect($response->json('data'))->pluck('code');

    // L'Afrique, mais pas seulement : les propriétaires et les clients vivent
    // aussi en Europe, en Amérique du Nord et dans le Golfe.
    expect($codes)->toContain('ML', 'CI', 'SN', 'CM', 'GN', 'NG', 'ZA')
        ->toContain('FR', 'DE', 'ES', 'GB', 'CA', 'US', 'AE');
});

it('sert la devise de chaque pays avec son indicatif', function () {
    $countries = collect($this->getJson('/api/countries')->json('data'))
        ->keyBy('code');

    expect($countries['FR']['currency'])->toBe('EUR')
        ->and($countries['FR']['dialingCode'])->toBe('33')
        ->and($countries['GB']['currency'])->toBe('GBP')
        ->and($countries['CA']['dialingCode'])->toBe('1')
        ->and($countries['NG']['currency'])->toBe('NGN');
});

it('enregistre le numéro avec l’indicatif du pays choisi', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Awa Koné',
        'country' => 'CI',
        'phone' => '07 08 12 34 56',
        'password' => 'secret123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.country', 'CI')
        ->assertJsonPath('user.phone', '+2250708123456');
});

it('retombe sur le pays par défaut quand l’application ne l’envoie pas', function () {
    // Les versions déjà installées ne connaissent pas le champ : leurs
    // inscriptions doivent continuer d'aboutir, au Mali comme avant.
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Moussa Diarra',
        'phone' => '76008201',
        'password' => 'secret123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.country', 'ML')
        ->assertJsonPath('user.phone', '+22376008201');
});

it('refuse un numéro qu’il n’a pas pu comprendre', function () {
    // Mieux vaut le dire que d'enregistrer un identifiant de connexion
    // inutilisable, que son propriétaire découvrirait à la connexion suivante.
    $this->postJson('/api/auth/register', [
        'name' => 'Test',
        'country' => 'ML',
        'phone' => '123',
        'password' => 'secret123',
    ])->assertStatus(422)->assertJsonValidationErrors('phone');
});

it('refuse un pays hors catalogue', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'Test',
        'country' => 'ZZ',
        'phone' => '76008201',
        'password' => 'secret123',
    ])->assertStatus(422)->assertJsonValidationErrors('country');
});

it('inscrit un commerçant vivant en France', function () {
    // Le numéro français s'enregistre au même format international que les
    // autres, et son business est proposé en euros.
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Aïcha Traoré',
        'country' => 'FR',
        'phone' => '06 12 34 56 78',
        'password' => 'secret123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.country', 'FR')
        ->assertJsonPath('user.phone', '+33612345678');

    $owner = User::where('phone', '+33612345678')->firstOrFail();
    Sanctum::actingAs($owner);

    $this->postJson('/api/businesses', [
        'name' => 'Épicerie Barbès',
        'type' => 'shop',
        'phone' => '0612345678',
    ])->assertSuccessful()->assertJsonPath('data.currency', 'EUR');
});

it('inscrit un commerçant nigérian', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'Chidi Okafor',
        'country' => 'NG',
        'phone' => '0803 123 4567',
        'password' => 'secret123',
    ])->assertCreated()->assertJsonPath('user.phone', '+2348031234567');
});

it('connecte un ivoirien avec son numéro local', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'Awa Koné',
        'country' => 'CI',
        'phone' => '0708123456',
        'password' => 'secret123',
    ])->assertCreated();

    $this->postJson('/api/auth/login', [
        'country' => 'CI',
        'phone' => '07 08 12 34 56',
        'password' => 'secret123',
    ])->assertOk()->assertJsonPath('user.phone', '+2250708123456');
});

it('connecte encore un compte enregistré sous un numéro local nu', function () {
    // Comptes créés avant la sélection du pays : sans cette tolérance, leurs
    // propriétaires seraient enfermés dehors du jour au lendemain.
    User::factory()->create([
        'phone' => '76008201',
        'country' => 'ML',
        'password' => bcrypt('secret123'),
    ]);

    $this->postJson('/api/auth/login', [
        'phone' => '76008201',
        'password' => 'secret123',
    ])->assertOk();
});

it('ne fait pas correspondre un numéro local à un compte d’un autre pays', function () {
    User::factory()->create([
        'phone' => '+22376008201',
        'country' => 'ML',
        'password' => bcrypt('secret123'),
    ]);

    $this->postJson('/api/auth/login', [
        'country' => 'CI',
        'phone' => '76008201',
        'password' => 'secret123',
    ])->assertStatus(401);
});

it('donne au business la devise du pays de son propriétaire', function () {
    $owner = User::factory()->create(['role' => 'owner', 'country' => 'GN']);
    Sanctum::actingAs($owner);

    $this->postJson('/api/businesses', [
        'name' => 'Pharmacie Kaloum',
        'type' => 'pharmacy',
        'phone' => '623456789',
    ])->assertSuccessful()->assertJsonPath('data.currency', 'GNF');
});

it('laisse le propriétaire choisir une autre devise que celle de son pays', function () {
    $owner = User::factory()->create(['role' => 'owner', 'country' => 'ML']);
    Sanctum::actingAs($owner);

    $this->postJson('/api/businesses', [
        'name' => 'Import Export',
        'type' => 'service',
        'phone' => '76008201',
        'currency' => 'EUR',
    ])->assertSuccessful()->assertJsonPath('data.currency', 'EUR');
});

it('refuse une devise hors catalogue', function () {
    // Sans liste fermée, une faute de saisie s'afficherait derrière chaque
    // montant, jusque sur les factures remises aux clients.
    $owner = User::factory()->create(['role' => 'owner']);
    Sanctum::actingAs($owner);

    $this->postJson('/api/businesses', [
        'name' => 'Boutique',
        'type' => 'shop',
        'phone' => '76008201',
        'currency' => 'ABC',
    ])->assertStatus(422)->assertJsonValidationErrors('currency');
});

it('libelle les encaissements dans la devise du business, pas dans celle envoyée', function () {
    // Une application restée sur une version antérieure envoie « XOF » en dur :
    // elle libellerait en francs CFA les encaissements d'une boutique de Conakry.
    $owner = User::factory()->create(['role' => 'owner', 'country' => 'GN']);

    $business = Business::create([
        'owner_id' => $owner->id,
        'name' => 'Pharmacie Kaloum',
        'type' => 'pharmacy',
        'currency' => 'GNF',
    ]);
    $business->staff()->attach($owner->id, ['role' => 'manager']);
    $business->subscription()->create([
        'plan' => 'pro',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
        'is_active' => true,
    ]);

    Sanctum::actingAs($owner);

    $response = $this->postJson("/api/businesses/{$business->id}/payments", [
        'phone' => '623456789',
        'name' => 'Client',
        'amount' => 50000,
        'currency' => 'XOF',
        'method' => 'cash',
    ]);

    $response->assertSuccessful();

    expect($response->json('data.currency') ?? $response->json('currency'))->toBe('GNF');
});

it('enregistre le client sous l’indicatif du pays du propriétaire', function () {
    $owner = User::factory()->create(['role' => 'owner', 'country' => 'CI']);

    $business = Business::create([
        'owner_id' => $owner->id,
        'name' => 'Boutique Abidjan',
        'type' => 'shop',
        'currency' => 'XOF',
    ]);
    $business->staff()->attach($owner->id, ['role' => 'manager']);

    Sanctum::actingAs($owner);

    $this->postJson("/api/businesses/{$business->id}/clients", [
        'name' => 'Client Abidjan',
        'phone' => '07 08 12 34 56',
    ])->assertSuccessful();

    $this->assertDatabaseHas('clients', [
        'business_id' => $business->id,
        'phone' => '+2250708123456',
    ]);
});
