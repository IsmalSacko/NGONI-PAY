<?php

use App\Livewire\Admin\Users\Index as UsersIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * La colonne `is_active` existait et le panneau permettait de la basculer, mais
 * rien ne la lisait : désactiver un compte n'avait aucun effet, son propriétaire
 * continuait de se connecter et d'encaisser.
 */
beforeEach(function () {
    $this->commercant = User::factory()->create([
        'phone' => '+22376008201',
        'password' => Hash::make('secret123'),
        'role' => 'owner',
    ]);
});

it('refuse la connexion d’un compte désactivé, en disant quoi faire', function () {
    $this->commercant->update(['is_active' => false]);

    $this->postJson('/api/auth/login', [
        'phone' => '76008201',
        'country' => 'ML',
        'password' => 'secret123',
    ])->assertStatus(403)
        ->assertJsonPath('code', 'ACCOUNT_DEACTIVATED')
        // Un « identifiants invalides » enverrait chercher un mot de passe perdu.
        ->assertJsonFragment(['message' => 'Votre compte a été désactivé. Contactez le service client pour le réactiver.']);
});

it('laisse se connecter un compte actif', function () {
    $this->postJson('/api/auth/login', [
        'phone' => '76008201',
        'country' => 'ML',
        'password' => 'secret123',
    ])->assertOk();
});

it('coupe l’accès d’un jeton déjà émis', function () {
    Sanctum::actingAs($this->commercant);

    $this->getJson('/api/auth/me')->assertOk();

    // La désactivation peut venir d'ailleurs qu'du panneau — un script, une
    // correction en base. Un jeton déjà émis ne doit pas survivre.
    $this->commercant->update(['is_active' => false]);

    $this->getJson('/api/auth/me')
        ->assertStatus(403)
        ->assertJsonPath('code', 'ACCOUNT_DEACTIVATED');
});

it('ne laisse pas réinitialiser le mot de passe d’un compte désactivé', function () {
    // Cela n'y donnerait pas accès, et laisserait croire le contraire.
    $this->commercant->update(['is_active' => false]);

    $this->postJson('/api/auth/forgot-password', [
        'phone' => '76008201',
        'new_password' => 'nouveau123',
        'new_password_confirmation' => 'nouveau123',
    ])->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_DEACTIVATED');
});

it('déconnecte immédiatement quand l’exploitant désactive le compte', function () {
    $admin = User::factory()->create([
        'phone' => '+22370000002',
        'role' => User::ROLE_SYSTEM_ADMIN,
    ]);

    // Un jeton en cours d'usage sur le téléphone du commerçant.
    $jeton = $this->commercant->createToken('api')->plainTextToken;

    expect($this->commercant->tokens()->count())->toBe(1);

    Livewire::actingAs($admin)
        ->test(UsersIndex::class)
        ->call('toggleActive', $this->commercant->id);

    expect($this->commercant->fresh()->is_active)->toBeFalse()
        // Sans révocation, le compte resterait ouvert sur son téléphone jusqu'à
        // la prochaine connexion : la désactivation ne serait pas immédiate.
        ->and($this->commercant->tokens()->count())->toBe(0);

    // `actingAs` de la ligne précédente resterait en vigueur : on oublie les
    // gardes pour que la requête ne s'authentifie que par le jeton.
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer $jeton")
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});

it('réactive un compte, qui peut se reconnecter', function () {
    $admin = User::factory()->create([
        'phone' => '+22370000003',
        'role' => User::ROLE_SYSTEM_ADMIN,
    ]);

    $this->commercant->update(['is_active' => false]);

    Livewire::actingAs($admin)
        ->test(UsersIndex::class)
        ->call('toggleActive', $this->commercant->id);

    expect($this->commercant->fresh()->is_active)->toBeTrue();

    $this->postJson('/api/auth/login', [
        'phone' => '76008201',
        'country' => 'ML',
        'password' => 'secret123',
    ])->assertOk();
});

it('interdit à l’exploitant de désactiver son propre compte', function () {
    $admin = User::factory()->create([
        'phone' => '+22370000004',
        'role' => User::ROLE_SYSTEM_ADMIN,
    ]);

    Livewire::actingAs($admin)
        ->test(UsersIndex::class)
        ->call('toggleActive', $admin->id);

    expect($admin->fresh()->is_active)->toBeTrue();
});

it('crée un compte actif', function () {
    // `is_active` valait `null` en mémoire après création — donc « faux » — et
    // tout code tenant le modèle frais voyait un compte désactivé.
    $reponse = $this->postJson('/api/auth/register', [
        'name' => 'Nouveau',
        'country' => 'ML',
        'phone' => '76112233',
        'password' => 'secret123',
    ])->assertCreated();

    $user = User::where('phone', '+22376112233')->firstOrFail();

    expect($user->is_active)->toBeTrue();
    expect($reponse->json('token'))->not->toBeEmpty();
});
