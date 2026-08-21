<?php

use App\Livewire\Admin\SubscriptionRequests\Index as RequestsIndex;
use App\Models\Business;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * L'instruction depuis la console, telle que l'exploitant la vit : il ouvre la
 * demande, tranche, et la file doit refléter sa décision immédiatement.
 */
beforeEach(function () {
    $this->admin = User::create([
        'name' => 'Admin',
        'phone' => '+22370000001',
        'password' => Hash::make('secret'),
        'role' => User::ROLE_SYSTEM_ADMIN,
        'is_active' => true,
    ]);

    $owner = User::create([
        'name' => 'Owner',
        'phone' => '+22371111112',
        'password' => Hash::make('secret'),
        'role' => User::ROLE_OWNER,
        'is_active' => true,
    ]);

    $this->business = Business::create([
        'owner_id' => $owner->id,
        'name' => 'Boutique Test',
        'type' => 'shop',
        'currency' => 'XOF',
        'is_active' => true,
    ]);

    Subscription::create([
        'business_id' => $this->business->id,
        'plan' => 'free',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(5),
        'is_active' => true,
    ]);

    $this->demande = SubscriptionRequest::create([
        'business_id' => $this->business->id,
        'requested_by_user_id' => $owner->id,
        'plan' => 'pro',
        'method' => 'cash',
        'amount_due' => 45000,
        'currency' => 'XOF',
        'cycle' => 'quarterly',
        'months' => 3,
        'status' => 'pending',
    ]);
});

test('approuver une demande active le plan et retire la demande de la file', function () {
    Livewire::actingAs($this->admin)
        ->test(RequestsIndex::class)
        ->assertSee('Boutique Test')
        ->call('startDeciding', $this->demande->id)
        ->set('decisionNote', 'Reçu 45 000 en espèces')
        ->call('approve', $this->demande->id)
        // La file est filtrée sur « en attente » : une demande tranchée en sort.
        ->assertDontSee('Instruire cette demande');

    $this->demande->refresh();

    expect($this->demande->status->value)->toBe('approved')
        ->and($this->demande->decided_by_user_id)->toBe($this->admin->id)
        ->and($this->demande->decision_note)->toBe('Reçu 45 000 en espèces')
        ->and($this->demande->decided_at)->not->toBeNull();

    $abonnement = $this->business->fresh()->subscription;

    expect($abonnement->plan)->toBe('pro')
        // Trois mois, la durée demandée.
        ->and(now()->diffInDays($abonnement->ends_at))->toBeGreaterThan(85);
});

test('le compteur des demandes en attente retombe à zéro', function () {
    $composant = Livewire::actingAs($this->admin)->test(RequestsIndex::class);

    expect($composant->viewData('compteurs')['pending'])->toBe(1);

    $composant->call('approve', $this->demande->id);

    expect($composant->viewData('compteurs')['pending'])->toBe(0)
        ->and($composant->viewData('compteurs')['approved'])->toBe(1);
});

test('refuser une demande laisse le plan inchangé', function () {
    Livewire::actingAs($this->admin)
        ->test(RequestsIndex::class)
        ->call('startDeciding', $this->demande->id)
        ->set('decisionNote', 'Aucun paiement reçu')
        ->call('refuse', $this->demande->id);

    $this->demande->refresh();

    expect($this->demande->status->value)->toBe('refused')
        ->and($this->business->fresh()->subscription->plan)->toBe('free');
});

test('une demande déjà tranchée ne se retranche pas', function () {
    // Deux approbations accorderaient deux trimestres pour un seul paiement.
    $composant = Livewire::actingAs($this->admin)->test(RequestsIndex::class);

    $composant->call('approve', $this->demande->id);
    $premiereFin = $this->business->fresh()->subscription->ends_at;

    $composant->call('approve', $this->demande->id);

    expect($this->business->fresh()->subscription->ends_at->toDateString())
        ->toBe($premiereFin->toDateString());
});

test('la durée demandée est visible dans la file', function () {
    // Sans elle, l'exploitant ne sait pas ce qu'il accorde : « 3 » ne dit pas si
    // c'est un trimestre ou trois mois à l'unité.
    Livewire::actingAs($this->admin)
        ->test(RequestsIndex::class)
        ->assertSee('Trimestriel');
});

test('la file propose d’instruire une demande en attente', function () {
    // Le bouton était rendu, mais invisible à l'écran : c'est ce que ce test
    // vérifie en premier — sa présence dans le HTML.
    Livewire::actingAs($this->admin)
        ->test(RequestsIndex::class)
        ->assertSee('Instruire cette demande')
        ->call('startDeciding', $this->demande->id)
        ->assertSee('Approuver')
        ->assertSee('Refuser');
});
