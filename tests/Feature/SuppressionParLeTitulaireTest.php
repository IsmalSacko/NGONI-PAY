<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Le titulaire supprime lui-même son compte depuis l'application (règle 5.1.1 de l'App Store). */
class SuppressionParLeTitulaireTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_titulaire_supprime_son_compte_et_ses_boutiques(): void
    {
        Mail::fake();
        Storage::fake('local');
        ['user' => $awa, 'boutique' => $boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Awa', 'pays' => 'ML', 'telephone' => '76008201', 'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(TenantContext::class)->setBoutique($boutique->id);
        Produit::create(['nom' => 'Riz', 'prix_vente' => 1000, 'stock' => 5]);
        $jeton = $awa->createToken('t')->plainTextToken;

        $this->withToken($jeton)->getJson('/api/moi/suppression')->assertOk()->assertJsonPath('data.boutiques.0', 'Boutique Awa');
        $this->withToken($jeton)->postJson('/api/moi/supprimer', ['password' => 'mauvais', 'confirmation' => 'SUPPRIMER'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->withToken($jeton)->postJson('/api/moi/supprimer', ['password' => 'password123', 'confirmation' => 'oui'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->assertNotNull(User::find($awa->id));

        $this->withToken($jeton)->postJson('/api/moi/supprimer', ['password' => 'password123', 'confirmation' => 'SUPPRIMER'])->assertOk();

        $this->assertNull(User::find($awa->id));
        $this->assertNull(Boutique::withoutGlobalScopes()->find($boutique->id));
        $this->assertSame(0, Produit::withoutGlobalScopes()->where('boutique_id', $boutique->id)->count());
    }

    public function test_un_exploitant_ne_se_supprime_pas_depuis_l_application(): void
    {
        $admin = User::factory()->create(['est_admin_plateforme' => true, 'password' => 'password123']);
        $this->withToken($admin->createToken('t')->plainTextToken)
            ->postJson('/api/moi/supprimer', ['password' => 'password123', 'confirmation' => 'SUPPRIMER'])->assertUnprocessable();
        $this->assertNotNull(User::find($admin->id));
    }
}
