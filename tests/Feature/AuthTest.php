<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_boutique_with_an_admin_user(): void
    {
        $response = $this->postJson('/api/inscription', [
            'nom_boutique' => 'Épicerie Test',
            'pays' => 'ML',
            'telephone' => '+223 76 00 00 00',
            'password' => 'password123',
            'nom_utilisateur' => 'Aminata',
        ]);

        $response->assertCreated()->assertJsonStructure(['boutique', 'user', 'token']);

        $this->assertDatabaseHas('boutiques', ['nom' => 'Épicerie Test', 'devise' => 'XOF']);
        $this->assertDatabaseHas('users', ['phone' => '+22376000000']);
    }

    public function test_admin_can_login_with_various_phone_formats(): void
    {
        app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Test',
            'pays' => 'ML',
            'telephone' => '+223 76 00 00 00',
            'email' => null,
            'password' => 'password123',
            'nom_utilisateur' => 'Aminata',
        ]);

        $response = $this->postJson('/api/connexion', [
            'telephone' => '76 00 00 00',
            'pays' => 'ML',
            'password' => 'password123',
        ]);

        $response->assertOk()->assertJsonStructure(['user', 'token']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Test',
            'pays' => 'ML',
            'telephone' => '+223 76 00 00 00',
            'email' => null,
            'password' => 'password123',
            'nom_utilisateur' => 'Aminata',
        ]);

        $this->postJson('/api/connexion', [
            'telephone' => '+223 76 00 00 00',
            'password' => 'wrong',
        ])->assertUnprocessable();
    }

    public function test_login_picks_the_account_whose_password_matches_when_a_number_is_shared(): void
    {
        // Repris de Ngoni Pay : le même numéro, une fois en local, une fois en
        // international, sur deux comptes distincts.
        $local = User::factory()->create(['phone' => '0605758494', 'password' => 'provisoire1']);
        User::factory()->create(['phone' => '+33605758494', 'password' => 'autre-compte']);

        $this->postJson('/api/connexion', [
            'telephone' => '06 05 75 84 94',
            'pays' => 'FR',
            'password' => 'provisoire1',
        ])->assertOk()->assertJsonPath('user.id', $local->id);

        $this->postJson('/api/connexion', [
            'telephone' => '0605758494',
            'pays' => 'FR',
            'password' => 'ni-l-un-ni-l-autre',
        ])->assertUnprocessable();
    }

    public function test_a_boutique_cannot_see_another_boutiques_products(): void
    {
        $registration = app(BoutiqueRegistrationService::class);

        $boutiqueA = $registration->register([
            'nom' => 'Boutique A', 'pays' => 'ML', 'telephone' => '+223 76 00 00 01',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Admin A',
        ]);

        // Le tenant courant est celui de la dernière inscription (Boutique A) :
        // le produit créé maintenant lui est donc rattaché, comme le ferait
        // une vraie requête authentifiée en tant qu'admin de Boutique A.
        app(TenantContext::class)->setBoutique($boutiqueA['boutique']->id);
        Produit::create(['nom' => 'Produit A', 'prix_vente' => 1000]);

        $boutiqueB = $registration->register([
            'nom' => 'Boutique B', 'pays' => 'ML', 'telephone' => '+223 76 00 00 02',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Admin B',
        ]);

        $tokenB = $boutiqueB['user']->createToken('test')->plainTextToken;

        $this->withToken($tokenB)
            ->getJson('/api/produits')
            ->assertOk()
            ->assertJsonCount(0);
    }
}
