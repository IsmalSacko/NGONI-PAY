<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Produits\Index as ProduitsIndex;
use App\Models\Boutique;
use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Money\Montant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Montants en unités mineures : centimes pour l'euro, francs pour le CFA.
 */
class CentimesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Montant::oublier();

        ['user' => $this->admin, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pharmacie', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Ismael',
        ]);
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        Montant::oublier();

        return $this->withToken($this->admin->createToken('t')->plainTextToken);
    }

    private function dansLaBoutique(): void
    {
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        Montant::oublier();
    }

    public function test_changer_de_devise_convertit_au_taux_fixe(): void
    {
        $this->dansLaBoutique();
        $riz = Produit::create(['nom' => 'Riz long', 'prix_vente' => 25000, 'taux_tva' => 0, 'stock' => 10]);
        $vente = $this->api()->postJson('/api/ventes', [
            'reference_locale' => (string) Str::uuid(),
            'lignes' => [['produit_id' => $riz->id, 'quantite' => 1]],
            'moyen_paiement' => 'especes',
            'montant_recu' => 50000,
        ])->assertCreated()->json('id');

        // XOF → EUR au taux fixe 655,957 : 25 000 F = 38,11 €.
        $this->api()->putJson('/api/boutique', ['nom' => 'Pharmacie', 'pays' => 'FR'])->assertOk()->assertJsonPath('data.devise', 'EUR');
        $this->assertSame(3811, $riz->fresh()->prix_vente);
        $this->api()->getJson("/api/ventes/{$vente}")
            ->assertJsonPath('total', 3811)
            ->assertJsonPath('montant_recu', 7622)
            ->assertJsonPath('lignes.0.prix_unitaire', 3811);

        // Retour en XOF : 38,11 € = 24 999 F (l'arrondi au centime près).
        $this->api()->putJson('/api/boutique', ['nom' => 'Pharmacie', 'pays' => 'ML'])->assertOk();
        $this->assertSame(24999, $riz->fresh()->prix_vente);
    }

    public function test_garder_les_memes_montants_ou_donner_son_taux(): void
    {
        $this->dansLaBoutique();
        $riz = Produit::create(['nom' => 'Riz long', 'prix_vente' => 25000, 'taux_tva' => 0, 'stock' => 10]);

        // Sans conversion : 25 000 F → 25 000,00 €.
        $this->api()->putJson('/api/boutique', ['nom' => 'P', 'pays' => 'FR', 'convertir' => false])->assertOk();
        $this->assertSame(2500000, $riz->fresh()->prix_vente);

        // EUR → GHS : pas de parité fixe, le taux est demandé.
        $this->api()->putJson('/api/boutique', ['nom' => 'P', 'pays' => 'GH'])
            ->assertUnprocessable()->assertJsonValidationErrors('taux');
        $this->api()->putJson('/api/boutique', ['nom' => 'P', 'pays' => 'GH', 'taux' => 0.08])->assertOk()->assertJsonPath('data.devise', 'GHS');
        // 25 000 € ÷ 0,08 = 312 500 GHS.
        $this->assertSame(31250000, $riz->fresh()->prix_vente);
    }

    public function test_en_euros_on_saisit_et_on_vend_des_centimes(): void
    {
        $this->api()->putJson('/api/boutique', ['nom' => 'Pharmacie', 'pays' => 'FR'])->assertOk();
        $this->dansLaBoutique();
        $this->actingAs($this->admin);

        Livewire::test(ProduitsIndex::class)
            ->call('nouveauProduit')
            ->set('nom', 'Doliprane')
            ->set('prix_vente', '2,50')
            ->set('taux_tva', '5.5')
            ->set('stock', '10')
            ->set('seuil_alerte', '2')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $doliprane = Produit::where('nom', 'Doliprane')->firstOrFail();
        $this->assertSame(250, $doliprane->prix_vente);

        $this->api()->postJson('/api/ventes', [
            'reference_locale' => (string) Str::uuid(),
            'lignes' => [['produit_id' => $doliprane->id, 'quantite' => 3], ['libelle' => 'Conseil', 'prix_unitaire' => 199, 'quantite' => 1]],
            'moyen_paiement' => 'especes',
            'montant_recu' => 1000,
        ])->assertCreated()->assertJsonPath('total', 949)->assertJsonPath('monnaie_rendue', 51);

        $this->dansLaBoutique();
        $this->get('/produits')->assertOk()->assertSee('2,50');
        $this->get('/ventes')->assertOk()->assertSee('9,49');
    }

    public function test_prix_d_achat_et_marge_caches_au_caissier(): void
    {
        $this->dansLaBoutique();
        $this->actingAs($this->admin);

        Livewire::test(ProduitsIndex::class)
            ->call('nouveauProduit')
            ->set('nom', 'Riz long')->set('prix_achat', '20 000')->set('prix_vente', '25 000')
            ->assertSee('Marge : 5 000 XOF par article (20 %)')
            ->set('taux_tva', '0')->set('stock', '10')->set('seuil_alerte', '2')
            ->call('enregistrer')->assertHasNoErrors();

        $riz = Produit::where('nom', 'Riz long')->firstOrFail();
        $this->assertSame(20000, $riz->prix_achat);

        $this->api()->getJson('/api/produits')->assertJsonPath('0.prix_achat', 20000);

        $this->dansLaBoutique();
        $caissier = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Caissier', 'phone' => '+22370000002', 'password' => 'password123']);
        $caissier->assignRole('caissier');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $reponse = $this->withToken($caissier->createToken('t')->plainTextToken)->getJson('/api/produits')->assertOk();
        $this->assertArrayNotHasKey('prix_achat', $reponse->json('0'));
    }
}
