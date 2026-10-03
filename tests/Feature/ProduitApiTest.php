<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CategorieProduit;
use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProduitApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $caissier;

    protected function setUp(): void
    {
        parent::setUp();

        $result = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Test', 'pays' => 'ML', 'telephone' => '+223 76 00 00 00',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Aminata',
        ]);
        $this->admin = $result['user'];

        app(TenantContext::class)->setBoutique($result['boutique']->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($result['boutique']->id);

        $this->caissier = User::create([
            'boutique_id' => $result['boutique']->id,
            'name' => 'Caissier', 'phone' => '+22370000000', 'password' => bcrypt('password123'),
        ]);
        $this->caissier->assignRole('caissier');
    }

    private function jeton(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_un_admin_cree_un_produit_avec_sa_categorie(): void
    {
        $categorie = CategorieProduit::create(['nom' => 'Alimentation']);

        $reponse = $this->withToken($this->jeton($this->admin))->postJson('/api/produits', [
            'nom' => 'Riz parfumé',
            'format' => 'Sac 5 kg',
            'categorie_produit_id' => $categorie->id,
            'prix_vente' => 4750,
            'taux_tva' => 18,
            'stock' => 40,
            'seuil_alerte' => 10,
        ]);

        $reponse->assertCreated()
            ->assertJsonPath('nom', 'Riz parfumé')
            ->assertJsonPath('categorie.nom', 'Alimentation');

        $this->assertDatabaseHas('produits', ['nom' => 'Riz parfumé', 'boutique_id' => $this->admin->boutique_id]);
    }

    public function test_un_caissier_ne_peut_ni_creer_ni_modifier_ni_supprimer_un_produit(): void
    {
        $produit = Produit::create(['nom' => 'Savon', 'prix_vente' => 250, 'stock' => 10]);
        $jeton = $this->jeton($this->caissier);

        $this->withToken($jeton)->postJson('/api/produits', ['nom' => 'X', 'prix_vente' => 100])->assertForbidden();
        $this->withToken($jeton)->putJson("/api/produits/{$produit->id}", ['nom' => 'Y'])->assertForbidden();
        $this->withToken($jeton)->deleteJson("/api/produits/{$produit->id}")->assertForbidden();
    }

    public function test_modifier_puis_supprimer_un_produit(): void
    {
        $produit = Produit::create(['nom' => 'Savon', 'prix_vente' => 250, 'stock' => 10]);
        $jeton = $this->jeton($this->admin);

        $this->withToken($jeton)->putJson("/api/produits/{$produit->id}", ['prix_vente' => 300])
            ->assertOk()->assertJsonPath('prix_vente', 300);

        $this->withToken($jeton)->deleteJson("/api/produits/{$produit->id}")->assertNoContent();
        $this->assertSoftDeleted('produits', ['id' => $produit->id]);
    }

    public function test_ajuster_le_stock_le_modifie_et_journalise_le_mouvement(): void
    {
        $produit = Produit::create(['nom' => 'Savon', 'prix_vente' => 250, 'stock' => 10]);

        $this->withToken($this->jeton($this->admin))
            ->postJson("/api/produits/{$produit->id}/ajuster-stock", ['stock' => 25, 'motif' => 'Réassort'])
            ->assertOk()
            ->assertJsonPath('stock', 25);

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'quantite' => 15,
            'stock_apres' => 25,
            'motif' => 'Réassort',
            'user_id' => $this->admin->id,
            'boutique_id' => $this->admin->boutique_id,
        ]);
    }

    public function test_ajuster_au_meme_stock_ne_journalise_rien(): void
    {
        $produit = Produit::create(['nom' => 'Savon', 'prix_vente' => 250, 'stock' => 10]);

        $this->withToken($this->jeton($this->admin))
            ->postJson("/api/produits/{$produit->id}/ajuster-stock", ['stock' => 10])
            ->assertOk();

        $this->assertDatabaseCount('mouvements_stock', 0);
    }

    public function test_le_stock_negatif_est_refuse(): void
    {
        $produit = Produit::create(['nom' => 'Savon', 'prix_vente' => 250, 'stock' => 10]);

        $this->withToken($this->jeton($this->admin))
            ->postJson("/api/produits/{$produit->id}/ajuster-stock", ['stock' => -3])
            ->assertUnprocessable();
    }

    public function test_un_caissier_ne_peut_pas_ajuster_le_stock(): void
    {
        $produit = Produit::create(['nom' => 'Savon', 'prix_vente' => 250, 'stock' => 10]);

        $this->withToken($this->jeton($this->caissier))
            ->postJson("/api/produits/{$produit->id}/ajuster-stock", ['stock' => 99])
            ->assertForbidden();

        $this->assertSame(10, $produit->fresh()->stock);
    }

    public function test_on_ne_peut_pas_ajuster_le_stock_dun_produit_dune_autre_boutique(): void
    {
        $produit = Produit::create(['nom' => 'Savon', 'prix_vente' => 250, 'stock' => 10]);

        $autre = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Autre boutique', 'pays' => 'ML', 'telephone' => '+223 76 00 00 09',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Autre admin',
        ]);

        $this->withToken($this->jeton($autre['user']))
            ->postJson("/api/produits/{$produit->id}/ajuster-stock", ['stock' => 0])
            ->assertNotFound();

        $this->assertSame(10, $produit->fresh()->stock);
    }

    public function test_un_code_barres_deja_utilise_est_refuse_proprement(): void
    {
        Produit::create(['nom' => 'Riz', 'prix_vente' => 4750, 'code_barre' => '6130000200021']);

        $this->withToken($this->jeton($this->admin))
            ->postJson('/api/produits', ['nom' => 'Autre riz', 'prix_vente' => 100, 'code_barre' => '6130000200021'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code_barre.0', 'Ce code-barres est déjà celui de « Riz ».');
    }

    public function test_supprimer_un_produit_libere_son_code_barres(): void
    {
        $produit = Produit::create(['nom' => 'Riz', 'prix_vente' => 4750, 'code_barre' => '6130000200021']);
        $jeton = $this->jeton($this->admin);

        $this->withToken($jeton)->deleteJson("/api/produits/{$produit->id}")->assertNoContent();

        $this->withToken($jeton)
            ->postJson('/api/produits', ['nom' => 'Riz bis', 'prix_vente' => 4750, 'code_barre' => '6130000200021'])
            ->assertCreated();
    }

    public function test_modifier_un_produit_ne_change_pas_son_stock_en_silence(): void
    {
        $produit = Produit::create(['nom' => 'Savon', 'prix_vente' => 250, 'stock' => 10]);

        $this->withToken($this->jeton($this->admin))
            ->putJson("/api/produits/{$produit->id}", ['prix_vente' => 300, 'stock' => 999])
            ->assertOk();

        $this->assertSame(10, $produit->fresh()->stock);
        $this->assertSame(300, $produit->fresh()->prix_vente);
    }

    public function test_une_categorie_dune_autre_boutique_est_refusee(): void
    {
        $autre = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Autre boutique', 'pays' => 'ML', 'telephone' => '+223 76 00 00 09',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Autre admin',
        ]);
        app(TenantContext::class)->setBoutique($autre['boutique']->id);
        $categorieEtrangere = CategorieProduit::create(['nom' => 'Secrète']);
        app(TenantContext::class)->setBoutique($this->admin->boutique_id);

        $this->withToken($this->jeton($this->admin))
            ->postJson('/api/produits', ['nom' => 'X', 'prix_vente' => 100, 'categorie_produit_id' => $categorieEtrangere->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('categorie_produit_id');
    }
}
