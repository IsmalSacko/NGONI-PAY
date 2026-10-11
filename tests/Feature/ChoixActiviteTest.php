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
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** À l'ouverture, le propriétaire choisit son métier ; la caisse s'y règle. */
class ChoixActiviteTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Chez Awa', 'pays' => 'ML', 'telephone' => '76008201', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
    }

    private function api(User $user)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($user->createToken('t')->plainTextToken);
    }

    private function exemples(): int
    {
        app(TenantContext::class)->setBoutique($this->boutique->id);

        return Produit::where('nom', 'like', '%(exemple)')->count();
    }

    public function test_une_boutique_neuve_choisit_son_activite_une_fois(): void
    {
        $this->api($this->awa)->getJson('/api/moi')->assertJsonPath('user.boutique.activite_a_choisir', true);

        $this->api($this->awa)->postJson('/api/boutique/demarrage', ['activite' => 'restaurant'])->assertOk()
            ->assertJsonPath('data.activite', 'restaurant')->assertJsonPath('data.activite_a_choisir', false);
        $this->assertSame(0, $this->exemples(), 'riz et huile n’ont rien à faire dans un restaurant');
        $this->api($this->awa)->getJson('/api/moi')->assertJsonPath('user.boutique.activite_a_choisir', false);
    }

    public function test_grossiste_c_est_un_commerce_qui_vend_au_detail_et_en_gros(): void
    {
        $this->api($this->awa)->postJson('/api/boutique/demarrage', ['activite' => 'grossiste'])->assertOk()
            ->assertJsonPath('data.activite', 'commerce')->assertJsonPath('data.mode_vente', 'detail_gros');
        $this->assertSame(2, $this->exemples(), 'un commerce garde ses exemples');
    }

    public function test_changer_d_activite_dans_les_reglages_vaut_choix(): void
    {
        $this->api($this->awa)->putJson('/api/boutique/activite', ['activite' => 'pharmacie'])->assertOk()->assertJsonPath('data.activite_a_choisir', false);
    }

    public function test_un_employe_ne_choisit_pas(): void
    {
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        $caissier = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Moussa', 'phone' => '+22370000002', 'password' => 'password123']);
        $caissier->assignRole('caissier');
        $this->api($caissier)->postJson('/api/boutique/demarrage', ['activite' => 'pressing'])->assertForbidden();
    }
}
