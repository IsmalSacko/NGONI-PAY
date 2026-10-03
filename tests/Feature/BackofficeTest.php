<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Categories\Index as CategoriesIndex;
use App\Livewire\Clients\Index as ClientsIndex;
use App\Livewire\Dashboard;
use App\Livewire\Produits\Index as ProduitsIndex;
use App\Livewire\Stocks\Index as StocksIndex;
use App\Livewire\Utilisateurs\Index as UtilisateursIndex;
use App\Livewire\Ventes\Index as VentesIndex;
use App\Models\CategorieProduit;
use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BackofficeTest extends TestCase
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
            'name' => 'Caissier Test',
            'phone' => '+22370000000',
            'password' => bcrypt('password123'),
        ]);
        $this->caissier->assignRole('caissier');
    }

    public function test_login_avec_un_bon_mot_de_passe_authentifie_et_redirige(): void
    {
        Livewire::test(Login::class)
            ->set('telephone', '+223 76 00 00 00')
            ->set('password', 'password123')
            ->call('connexion')
            ->assertRedirect(route('tableau-de-bord'));

        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_login_avec_un_numero_local_ivoirien_et_le_pays_choisi(): void
    {
        $gerante = User::create([
            'boutique_id' => $this->admin->boutique_id,
            'name' => 'Gérante Abidjan',
            'phone' => '+2250708123456',
            'password' => bcrypt('password123'),
        ]);

        // Sans le pays, « 0708123456 » serait lu comme un numéro malien.
        Livewire::test(Login::class)
            ->set('pays', 'CI')
            ->set('telephone', '07 08 12 34 56')
            ->set('password', 'password123')
            ->call('connexion')
            ->assertRedirect(route('tableau-de-bord'));

        $this->assertAuthenticatedAs($gerante);
    }

    public function test_login_web_departage_deux_comptes_sur_le_meme_numero(): void
    {
        $local = User::create(['boutique_id' => $this->admin->boutique_id, 'name' => 'Compte local',
            'phone' => '0605758494', 'password' => bcrypt('provisoire1')]);
        User::create(['boutique_id' => $this->admin->boutique_id, 'name' => 'Doublon',
            'phone' => '+33605758494', 'password' => bcrypt('autre-compte')]);

        Livewire::test(Login::class)
            ->set('pays', 'FR')
            ->set('telephone', '0605758494')
            ->set('password', 'provisoire1')
            ->call('connexion')
            ->assertRedirect(route('tableau-de-bord'));

        $this->assertAuthenticatedAs($local);
    }

    public function test_la_page_de_connexion_propose_la_recherche_du_pays(): void
    {
        $this->get('/connexion')
            ->assertOk()
            ->assertSee('Rechercher un pays ou un indicatif')
            ->assertSee('Côte d', false);
    }

    public function test_dashboard_affiche_les_kpis_de_la_boutique(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(Dashboard::class)
            ->assertSee('Tableau de bord')
            ->assertSee('Épicerie Test');
    }

    public function test_admin_peut_creer_un_produit_qui_est_rattache_a_sa_boutique(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ProduitsIndex::class)
            ->call('nouveauProduit')
            ->set('nom', 'Riz parfumé')
            ->set('prix_vente', '4750')
            ->call('enregistrer')
            ->assertSet('modaleOuverte', false);

        $produit = Produit::where('nom', 'Riz parfumé')->firstOrFail();
        $this->assertSame($this->admin->boutique_id, $produit->boutique_id);
    }

    public function test_caissier_ne_peut_pas_creer_un_produit(): void
    {
        $this->actingAs($this->caissier);

        Livewire::test(ProduitsIndex::class)
            ->call('nouveauProduit')
            ->set('nom', 'Produit interdit')
            ->set('prix_vente', '1000')
            ->call('enregistrer')
            ->assertForbidden();

        $this->assertDatabaseMissing('produits', ['nom' => 'Produit interdit']);
    }

    public function test_categories_index_permet_de_creer_une_categorie(): void
    {
        $this->actingAs($this->admin);
        $page = Livewire::test(CategoriesIndex::class);
        $libre = $page->get('couleur');
        $this->assertArrayHasKey($libre, CategorieProduit::COULEURS, 'une couleur de la palette, déjà cochée');
        $this->assertNotContains($libre, CategorieProduit::withoutGlobalScopes()->where('boutique_id', $this->admin->boutique_id)->pluck('couleur')->all(), 'pas déjà prise');
        $page->set('nom', 'Épicerie fine')->call('enregistrer');

        $categorie = CategorieProduit::where('nom', 'Épicerie fine')->firstOrFail();
        $this->assertSame($this->admin->boutique_id, $categorie->boutique_id);
        $this->assertSame($libre, $categorie->couleur, 'la première couleur libre, déjà cochée');

        // On coche une autre couleur ; la suivante proposée n'est plus la même.
        Livewire::test(CategoriesIndex::class)
            ->assertNotSet('couleur', $libre)
            ->assertSee('Turquoise')
            ->set('nom', 'Téléphonie')->call('$set', 'couleur', '#D61F69')->call('enregistrer');
        $this->assertSame('#D61F69', CategorieProduit::where('nom', 'Téléphonie')->value('couleur'));
    }

    public function test_clients_index_permet_de_creer_un_client(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ClientsIndex::class)
            ->call('nouveauClient')
            ->set('nom', 'Moussa Traoré')
            ->call('enregistrer');

        $this->assertDatabaseHas('clients', ['nom' => 'Moussa Traoré', 'boutique_id' => $this->admin->boutique_id]);
    }

    public function test_stocks_index_ajuste_le_stock_et_journalise_le_mouvement(): void
    {
        $this->actingAs($this->admin);
        $produit = Produit::create(['nom' => 'Savon', 'prix_vente' => 250, 'stock' => 10]);

        Livewire::test(StocksIndex::class)
            ->call('ouvrirAjustement', $produit->id)
            ->set('nouveauStock', '25')
            ->set('motif', 'Inventaire')
            ->call('enregistrerAjustement');

        $this->assertSame(25, $produit->fresh()->stock);
        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'quantite' => 15,
            'stock_apres' => 25,
            'boutique_id' => $this->admin->boutique_id,
        ]);
    }

    public function test_ventes_index_liste_les_ventes_de_la_boutique(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(VentesIndex::class)->assertSee('Ventes');
    }

    public function test_admin_peut_creer_un_compte_utilisateur_avec_un_role(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(UtilisateursIndex::class)
            ->call('nouveauCompte')
            ->set('name', 'Nouveau Gérant')
            ->set('telephone', '+223 79 00 00 00')
            ->set('password', 'password123')
            ->set('role', 'gerant')
            ->call('enregistrer');

        $nouveau = User::where('name', 'Nouveau Gérant')->firstOrFail();
        $this->assertTrue($nouveau->hasRole('gerant'));
        $this->assertSame($this->admin->boutique_id, $nouveau->boutique_id);
    }

    public function test_caissier_ne_peut_pas_creer_de_compte_utilisateur(): void
    {
        $this->actingAs($this->caissier);

        Livewire::test(UtilisateursIndex::class)
            ->call('nouveauCompte')
            ->set('name', 'Intrus')
            ->set('telephone', '+223 79 00 00 01')
            ->set('password', 'password123')
            ->set('role', 'admin')
            ->call('enregistrer')
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['name' => 'Intrus']);
    }
}
