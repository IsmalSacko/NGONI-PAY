<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_se_deconnecter_revoque_le_jeton_et_reussit_meme_avec_un_jeton_deja_invalide(): void
    {
        ['user' => $user] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique', 'pays' => 'ML', 'telephone' => '76008299', 'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        $jeton = $user->createToken('t')->plainTextToken;

        $this->withToken($jeton)->postJson('/api/deconnexion')->assertOk();
        $this->assertSame(0, $user->tokens()->count(), 'jeton révoqué');

        // Le même jeton, désormais invalide : 200, et non 401 — l'application
        // relançait la déconnexion à chaque 401, en boucle.
        $this->app['auth']->forgetGuards();
        $this->withToken($jeton)->postJson('/api/deconnexion')->assertOk();
        $this->postJson('/api/deconnexion')->assertOk();
    }

    public function test_un_numero_deja_inscrit_est_refuse_clairement_et_non_par_une_erreur_serveur(): void
    {
        $inscription = [
            'nom_boutique' => 'Boutique Koné', 'pays' => 'CI', 'telephone' => '0500144045',
            'password' => 'password123', 'nom_utilisateur' => 'Koné',
        ];
        $this->postJson('/api/inscription', $inscription)->assertCreated();

        $this->postJson('/api/inscription', [...$inscription, 'nom_boutique' => 'Autre boutique'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('telephone')
            ->assertJsonFragment(['Ce numéro a déjà un compte Ngoni Caisse. Connectez-vous, ou utilisez « Mot de passe oublié ».']);
    }

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
            ->assertJsonMissing(['nom' => 'Produit A'])
            ->assertJsonCount(2); // ses deux articles de départ, rien de la boutique A
    }

    public function test_une_connexion_refusee_est_tracee_avec_sa_cause_jamais_le_mot_de_passe(): void
    {
        app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Test', 'pays' => 'ML', 'telephone' => '76000000',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Aminata',
        ]);
        $traces = [];
        Log::listen(function ($m) use (&$traces) {
            $traces[] = $m->message.' '.json_encode($m->context);
        });

        $this->postJson('/api/connexion', ['telephone' => '76000000', 'pays' => 'FR', 'password' => 'password123'])->assertUnprocessable();
        $this->postJson('/api/connexion', ['telephone' => '76000000', 'pays' => 'ML', 'password' => 'password123 '])->assertUnprocessable();
        $this->postJson('/api/connexion', ['telephone' => '76000000', 'pays' => 'ML', 'password' => 'secret-faux'])->assertUnprocessable();

        $this->assertStringContainsString('aucun compte pour ce numéro dans ce pays', $traces[0]);
        $this->assertStringContainsString('espaces autour du mot de passe', $traces[1]);
        $this->assertStringContainsString('mot de passe différent', $traces[2]);
        foreach ($traces as $trace) {
            $this->assertStringNotContainsString('password123', $trace);
            $this->assertStringNotContainsString('secret-faux', $trace);
        }
    }
}
