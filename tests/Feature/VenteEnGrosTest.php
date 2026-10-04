<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Boutiques\Index;
use App\Models\Boutique;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Produit;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Vente en gros : prix de gros par article, à partir d'un seuil, pour un
 * client revendeur ou par la bascule (droit de remise) ; seulement dans une
 * boutique « au détail et en gros » dont l'offre l'inclut.
 */
class VenteEnGrosTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    private Produit $savon;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Demi-gros Awa', 'pays' => 'ML', 'telephone' => '76008201', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        Produit::query()->delete();
        $this->savon = Produit::create(['nom' => 'Savon', 'prix_vente' => 200, 'prix_gros' => 180, 'seuil_gros' => 12, 'taux_tva' => 0, 'stock' => 500]);
    }

    private function api(User $user)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($user->createToken('t')->plainTextToken);
    }

    private function vendre(User $user, int $quantite, array $extra = [])
    {
        return $this->api($user)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $this->savon->id, 'quantite' => $quantite]], 'moyen_paiement' => 'especes', ...$extra,
        ]);
    }

    public function test_une_boutique_au_detail_ignore_le_prix_de_gros(): void
    {
        $this->vendre($this->awa, 24, ['tarif' => 'gros'])->assertCreated()->assertJsonPath('total', 4800)->assertJsonPath('tarif', 'detail');
    }

    public function test_au_detail_et_en_gros_le_seuil_le_revendeur_et_la_bascule(): void
    {
        $this->api($this->awa)->putJson('/api/boutique/ventes', ['mode_vente' => 'detail_gros'])->assertOk()->assertJsonPath('data.mode_vente', 'detail_gros');

        $this->vendre($this->awa, 2)->assertCreated()->assertJsonPath('total', 400);
        $parSeuil = $this->vendre($this->awa, 12)->assertCreated();
        $this->assertSame(2160, $parSeuil->json('total'), '12 savons : le seuil est atteint');
        $this->assertTrue($parSeuil->json('lignes.0.prix_gros'));
        $this->assertSame(200, $parSeuil->json('lignes.0.prix_detail'), 'le ticket barre le prix de détail');
        $this->assertSame('detail', $parSeuil->json('tarif'), 'la vente reste au détail, seule la ligne passe au gros');

        $revendeur = Client::create(['nom' => 'Boutique Diallo', 'revendeur' => true]);
        $this->vendre($this->awa, 2, ['client_id' => $revendeur->id])->assertCreated()->assertJsonPath('total', 360)->assertJsonPath('tarif', 'gros');

        $this->vendre($this->awa, 3, ['tarif' => 'gros'])->assertCreated()->assertJsonPath('total', 540);

        $rapport = $this->api($this->awa)->getJson('/api/rapports?du='.today()->toDateString().'&au='.today()->toDateString())->assertOk();
        $this->assertSame(['gros' => 2160 + 360 + 540, 'detail' => 400], $rapport->json('par_tarif'));
    }

    public function test_la_bascule_demande_le_droit_de_remise_sauf_pour_un_revendeur(): void
    {
        $this->boutique->forceFill(['mode_vente' => 'detail_gros'])->save();
        $caissier = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Moussa', 'phone' => '+22370000009', 'password' => bcrypt('password123')]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        $caissier->assignRole('caissier');
        $this->assertFalse($caissier->fresh()->can('ventes.remise'));

        $this->vendre($caissier, 3, ['tarif' => 'gros'])->assertForbidden();
        $revendeur = Client::create(['nom' => 'Boutique Diallo', 'revendeur' => true]);
        $this->vendre($caissier, 3, ['client_id' => $revendeur->id])->assertCreated()->assertJsonPath('total', 540);
    }

    public function test_hors_de_l_offre_le_prix_de_gros_est_garde_mais_ne_s_applique_pas(): void
    {
        $this->boutique->forceFill(['mode_vente' => 'detail_gros'])->save();
        // Offre Basic en cours, qui n'inclut pas la vente en gros.
        $this->awa->abonnement()->update(['plan' => 'basic', 'fin' => now()->addMonth()->toDateString()]);
        $this->assertFalse(app(AbonnementService::class)->permet($this->boutique->fresh(), Plan::VENTE_GROS));
        $this->vendre($this->awa, 24, ['tarif' => 'gros'])->assertCreated()->assertJsonPath('total', 4800);
        $this->assertSame(180, $this->savon->fresh()->prix_gros);
    }

    public function test_le_back_office_regle_vos_ventes_le_prix_de_gros_et_le_revendeur(): void
    {
        $this->actingAs($this->awa);
        Livewire::test(Index::class)
            ->assertSee('Vos ventes')
            ->call('choisirModeVente', 'detail_gros');
        $this->assertSame('detail_gros', $this->boutique->fresh()->mode_vente);

        Livewire::test(\App\Livewire\Produits\Index::class)
            ->call('modifier', $this->savon->id)
            ->assertSee('Prix de gros')
            ->set('prix_gros', '170')
            ->set('seuil_gros', '24')
            ->call('enregistrer')
            ->assertHasNoErrors();
        $this->assertSame([170, 24], [(int) $this->savon->fresh()->prix_gros, $this->savon->fresh()->seuil_gros]);

        Livewire::test(\App\Livewire\Clients\Index::class)
            ->call('nouveauClient')
            ->assertSee('Revendeur')
            ->set('nom', 'Boutique Diallo')
            ->set('revendeur', true)
            ->call('enregistrer')
            ->assertHasNoErrors();
        $this->assertTrue((bool) Client::where('nom', 'Boutique Diallo')->value('revendeur'));
    }

    public function test_une_application_d_avant_la_vente_en_gros_doit_se_mettre_a_jour(): void
    {
        Cache::flush();
        $ancienne = fn () => $this->api($this->awa)->withHeaders(['X-Appareil-Plateforme' => 'android', 'X-App-Version' => '4.10.5']);
        $ancienne()->getJson('/api/produits')->assertOk();

        $this->boutique->forceFill(['mode_vente' => 'detail_gros'])->save();
        Cache::flush();
        $ancienne()->getJson('/api/produits')->assertStatus(426)->assertJsonPath('code', 'MISE_A_JOUR_REQUISE');
        $this->api($this->awa)->withHeaders(['X-Appareil-Plateforme' => 'android', 'X-App-Version' => '4.10.6'])->getJson('/api/produits')->assertOk();
    }

    public function test_une_vente_d_une_ancienne_application_garde_le_prix_de_detail_qu_elle_a_encaisse(): void
    {
        $this->boutique->forceFill(['mode_vente' => 'detail_gros'])->save();
        // POST /ventes reste ouvert aux anciennes versions (ventes hors ligne).
        $this->api($this->awa)->withHeaders(['X-Appareil-Plateforme' => 'android', 'X-App-Version' => '4.10.5'])
            ->postJson('/api/ventes', ['lignes' => [['produit_id' => $this->savon->id, 'quantite' => 12]], 'moyen_paiement' => 'especes'])
            ->assertCreated()->assertJsonPath('total', 2400)->assertJsonPath('lignes.0.prix_gros', false);
    }

    public function test_si_chaque_vente_commence_en_gros_le_caissier_vend_en_gros_sans_droit_de_remise(): void
    {
        $this->boutique->forceFill(['mode_vente' => 'detail_gros', 'vente_commence_en_gros' => true])->save();
        $caissier = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Moussa', 'phone' => '+22370000009', 'password' => bcrypt('password123')]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        $caissier->assignRole('caissier');
        $this->vendre($caissier, 3, ['tarif' => 'gros'])->assertCreated()->assertJsonPath('total', 540);
    }
}
