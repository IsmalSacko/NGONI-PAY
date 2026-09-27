<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProfilTest extends TestCase
{
    use RefreshDatabase;

    private User $caissier;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['boutique' => $boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Awa', 'pays' => 'ML', 'telephone' => '76008201', 'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(TenantContext::class)->setBoutique($boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($boutique->id);
        $this->caissier = User::create(['boutique_id' => $boutique->id, 'name' => 'Moussa', 'phone' => '+22370000001', 'password' => 'ancien-mdp']);
        $this->caissier->assignRole('caissier');
    }

    public function test_un_caissier_modifie_son_profil_mais_pas_son_role(): void
    {
        $jeton = $this->caissier->createToken('app')->plainTextToken;

        $this->withToken($jeton)->putJson('/api/moi', [
            'name' => 'Moussa Diarra', 'telephone' => '70 00 00 09', 'email' => 'moussa@example.com', 'role' => 'admin',
        ])->assertOk()->assertJsonPath('user.name', 'Moussa Diarra')->assertJsonPath('user.phone', '+22370000009');

        $moussa = $this->caissier->fresh();
        $this->assertSame('moussa@example.com', $moussa->email);
        $this->assertSame(['caissier'], $moussa->getRoleNames()->all(), 'le rôle ne bouge pas');
    }

    public function test_changer_son_mot_de_passe_demande_l_actuel_et_deconnecte_les_autres_appareils(): void
    {
        $jeton = $this->caissier->createToken('ce-telephone')->plainTextToken;
        $this->caissier->createToken('autre-telephone');

        $this->withToken($jeton)->putJson('/api/moi/mot-de-passe', [
            'mot_de_passe_actuel' => 'faux', 'mot_de_passe' => 'nouveau-mdp', 'mot_de_passe_confirmation' => 'nouveau-mdp',
        ])->assertUnprocessable()->assertJsonValidationErrors('mot_de_passe_actuel');

        $this->withToken($jeton)->putJson('/api/moi/mot-de-passe', [
            'mot_de_passe_actuel' => 'ancien-mdp', 'mot_de_passe' => 'court', 'mot_de_passe_confirmation' => 'court',
        ])->assertUnprocessable()->assertJsonValidationErrors('mot_de_passe');

        $this->withToken($jeton)->putJson('/api/moi/mot-de-passe', [
            'mot_de_passe_actuel' => 'ancien-mdp', 'mot_de_passe' => 'nouveau-mdp', 'mot_de_passe_confirmation' => 'nouveau-mdp',
        ])->assertOk();

        $this->assertTrue(Hash::check('nouveau-mdp', $this->caissier->fresh()->password));
        $this->assertSame(['ce-telephone'], $this->caissier->tokens()->pluck('name')->all(), 'seul cet appareil reste connecté');
    }
}
