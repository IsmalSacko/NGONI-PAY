<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\CategorieProduit;
use App\Models\Client;
use App\Models\IngredientRestaurant;
use App\Models\NotificationApp;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Restaurant : commande prise en salle ou par téléphone, cuisine, service, et
 * l'addition (payée tout de suite ou plus tard, partagée) où naît la vente.
 * Les autres boutiques n'y ont pas accès et ne changent pas.
 */
class RestaurantTest extends TestCase
{
    use RefreshDatabase;

    private User $chef;

    private Boutique $boutique;

    private string $poulet;

    private string $bissap;

    private string $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->chef, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Chez Awa', 'pays' => 'ML', 'telephone' => '76008201', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        Produit::query()->delete();
        $this->client = Client::create(['nom' => 'Moussa Diarra', 'telephone' => '+22370112233'])->id;
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'restaurant'])->assertOk();
        $this->poulet = $this->api()->postJson('/api/produits', ['nom' => 'Poulet braisé', 'prix_vente' => 3000, 'taux_tva' => 0])->assertCreated()->json('id');
        $this->bissap = $this->api()->postJson('/api/produits', ['nom' => 'Bissap', 'prix_vente' => 500, 'taux_tva' => 0])->assertCreated()->json('id');
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($this->chef->createToken('t')->plainTextToken);
    }

    private function commander(array $extra = [])
    {
        return $this->api()->postJson('/api/restaurant/commandes', [
            'type' => 'sur_place', 'table' => 'Table 4', 'couverts' => 2,
            'lignes' => [
                ['produit_id' => $this->poulet, 'quantite' => 2, 'note' => 'sans piment'],
                ['produit_id' => $this->bissap, 'quantite' => 2],
            ],
            ...$extra,
        ]);
    }

    public function test_commande_a_table_cuisine_service_puis_addition(): void
    {
        $c = $this->commander()->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^[1-9]\d{5}$/', $c['numero']);
        $this->assertSame(7000, $c['total']);
        $this->assertSame(['en_cuisine', 'en_cuisine'], array_column($c['lignes'], 'etat'), 'partie en cuisine à la validation');
        $this->assertSame(0, Vente::count(), 'pas de vente avant l’addition');

        // La cuisine la voit, envoi n° 1.
        $cuisine = $this->api()->getJson('/api/restaurant/cuisine')->assertOk()->json('data');
        $this->assertSame([1], array_column($cuisine, 'envoi'));
        $this->assertSame('sans piment', $cuisine[0]['lignes'][0]['note']);

        // Un dessert en plus pendant le repas : envoi n° 2.
        $this->api()->postJson("/api/restaurant/commandes/{$c['id']}/lignes", ['lignes' => [['produit_id' => $this->bissap, 'quantite' => 1]]])
            ->assertOk()->assertJsonPath('data.total', 7500)->assertJsonPath('data.envois', 2);

        $this->api()->postJson("/api/restaurant/commandes/{$c['id']}/prets")->assertOk();
        $this->api()->getJson('/api/restaurant/compteurs')->assertJsonPath('data.pretes', 5)->assertJsonPath('data.a_payer', 1);
        $this->api()->postJson("/api/restaurant/commandes/{$c['id']}/servir")->assertOk()->assertJsonPath('data.en_cours', true);

        $paye = $this->api()->postJson("/api/restaurant/commandes/{$c['id']}/payer", ['moyen_paiement' => 'especes', 'montant_donne' => 10000, 'pourboire' => 500])->assertOk();
        $paye->assertJsonPath('data.statut', 'payee')->assertJsonPath('data.en_cours', false)->assertJsonPath('data.pourboire', 500);
        $vente = Vente::with('lignes')->findOrFail($paye->json('vente_id'));
        $this->assertSame(7500, (int) $vente->total);
        $this->assertSame($c['id'], $vente->commande_restaurant_id);
        $this->assertContains('Poulet braisé', $vente->lignes->pluck('nom_produit')->all());
        $this->assertSame(2500, (int) $vente->monnaie_rendue);
        $this->api()->getJson("/api/ventes/{$vente->id}")->assertOk()->assertJsonPath('commande_restaurant.table', 'Table 4');
    }

    public function test_payer_maintenant_au_comptoir(): void
    {
        $c = $this->commander(['type' => 'emporter', 'table' => null, 'paiement' => ['moyen_paiement' => 'orange_money']])->assertCreated();
        $c->assertJsonPath('data.statut', 'payee')->assertJsonPath('data.reste', 0)->assertJsonPath('data.en_cours', true);
        $this->assertNotNull($c->json('vente_id'));
        // Payée mais pas encore remise : la cuisine la prépare toujours.
        $this->assertCount(1, $this->api()->getJson('/api/restaurant/cuisine')->json('data'));
        $this->api()->postJson("/api/restaurant/commandes/{$c->json('data.id')}/servir")->assertJsonPath('data.en_cours', false);
    }

    public function test_commande_par_telephone_acompte_puis_paiement_au_retrait(): void
    {
        $this->commander(['telephone' => true, 'type' => 'emporter'])->assertUnprocessable()->assertJsonValidationErrors('client_id');
        $this->commander(['telephone' => true, 'client_id' => $this->client])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->api()->postJson('/api/sessions-caisse', ['fond_initial' => 10000])->assertCreated();
        $c = $this->commander([
            'telephone' => true, 'type' => 'emporter', 'client_id' => $this->client, 'heure_prevue' => now()->addHour()->toIso8601String(),
            'acompte' => 2000, 'moyen_acompte' => 'especes',
        ])->assertCreated()->json('data');
        $this->assertSame(5000, $c['reste']);
        $this->api()->getJson('/api/sessions-caisse/courante')->assertJsonPath('fond_attendu', 12000);

        $this->api()->postJson("/api/restaurant/commandes/{$c['id']}/payer", ['moyen_paiement' => 'especes'])->assertOk()->assertJsonPath('data.reste', 0);
        $this->assertSame(2000, (int) Vente::sole()->acompte_deduit);
        $this->api()->getJson('/api/sessions-caisse/courante')->assertJsonPath('fond_attendu', 17000);
        $jour = now()->toDateString();
        $this->api()->getJson("/api/rapports?du={$jour}&au={$jour}")->assertJsonPath('encaisse.acomptes', 2000)->assertJsonPath('encaisse.ventes', 5000);
    }

    public function test_addition_partagee_par_plats(): void
    {
        $c = $this->commander()->json('data');
        $poulets = collect($c['lignes'])->firstWhere('produit_id', $this->poulet)['id'];
        $this->api()->postJson("/api/restaurant/commandes/{$c['id']}/payer", ['moyen_paiement' => 'especes', 'lignes' => [$poulets]])
            ->assertOk()->assertJsonPath('data.statut', 'ouverte')->assertJsonPath('data.reste', 1000);
        $this->api()->postJson("/api/restaurant/commandes/{$c['id']}/payer", ['moyen_paiement' => 'wave'])
            ->assertOk()->assertJsonPath('data.statut', 'payee');
        $this->assertSame([6000, 1000], Vente::orderBy('created_at')->pluck('total')->map(fn ($t) => (int) $t)->all());
    }

    public function test_options_formule_et_recette_consommee_en_cuisine(): void
    {
        $frites = $this->api()->postJson("/api/restaurant/carte/{$this->poulet}/options", ['groupe' => 'Accompagnement', 'nom' => 'Frites', 'prix' => 500])->assertCreated()->json('data.id');
        $menu = $this->api()->postJson('/api/produits', ['nom' => 'Menu midi', 'prix_vente' => 3200, 'taux_tva' => 0])->json('id');
        $this->api()->putJson("/api/restaurant/carte/{$menu}/formule", ['etapes' => [
            ['titre' => 'Plat', 'produits' => [$this->poulet]], ['titre' => 'Boisson', 'produits' => [$this->bissap]],
        ]])->assertOk();
        $poulet = $this->api()->postJson('/api/restaurant/ingredients', ['nom' => 'Poulet', 'unite' => 'pièce', 'quantite' => 10, 'seuil' => 3, 'cout_unitaire' => 1500])->json('data.id');
        $this->api()->putJson("/api/restaurant/carte/{$this->poulet}/recette", ['ingredients' => [['ingredient_id' => $poulet, 'quantite' => 0.5]]])->assertOk();

        $carte = collect($this->api()->getJson('/api/restaurant/carte')->json('data'))->keyBy('nom');
        $this->assertSame(750, $carte['Poulet braisé']['cout']);
        $this->assertSame(2250, $carte['Poulet braisé']['marge']);

        $c = $this->api()->postJson('/api/restaurant/commandes', ['type' => 'sur_place', 'table' => 'T1', 'lignes' => [
            ['produit_id' => $this->poulet, 'quantite' => 2, 'options' => [$frites]],
            ['produit_id' => $menu, 'quantite' => 1, 'composition' => [$this->poulet, $this->bissap]],
        ]])->assertCreated()->json('data');
        $this->assertSame('Poulet braisé (Frites)', $c['lignes'][0]['nom']);
        $this->assertSame(3500, $c['lignes'][0]['prix_unitaire']);
        $this->assertSame('Menu midi (Poulet braisé, Bissap)', $c['lignes'][1]['nom']);
        $this->assertSame(2 * 3500 + 3200, $c['total']);
        // 2 poulets + 1 dans le menu, à 0,5 chacun : 1,5 pièce sortie du stock.
        $this->assertEqualsWithDelta(8.5, IngredientRestaurant::sole()->quantite, 0.001);

        $this->api()->postJson('/api/restaurant/commandes', ['table' => 'T2', 'lignes' => [['produit_id' => $menu, 'quantite' => 1, 'composition' => [$this->bissap, $this->bissap]]]])
            ->assertUnprocessable();
    }

    public function test_achat_d_ingredient_devient_depense_et_fixe_le_cout(): void
    {
        $riz = $this->api()->postJson('/api/restaurant/ingredients', ['nom' => 'Riz', 'unite' => 'kg', 'seuil' => 5])->assertCreated()->assertJsonPath('data.a_racheter', true)->json('data.id');
        $this->api()->postJson("/api/restaurant/ingredients/{$riz}/mouvement", ['quantite' => 25, 'montant' => 15000])->assertOk()
            ->assertJsonPath('data.cout_unitaire', 600)->assertJsonPath('data.a_racheter', false);
        $this->api()->getJson('/api/depenses')->assertOk()->assertJsonPath('data.0.libelle', 'Achat : Riz');
    }

    public function test_transfert_fusion_annulation_et_plat_retire(): void
    {
        $a = $this->commander()->json('data');
        $b = $this->commander(['table' => 'Table 5', 'couverts' => 3])->json('data');
        $this->api()->postJson("/api/restaurant/commandes/{$a['id']}/transferer", ['table' => 'Terrasse 1'])->assertOk()->assertJsonPath('data.table', 'Terrasse 1');
        $this->api()->postJson("/api/restaurant/commandes/{$a['id']}/fusionner", ['autre_id' => $b['id']])->assertOk()
            ->assertJsonPath('data.total', 14000)->assertJsonPath('data.couverts', 5)->assertJsonCount(4, 'data.lignes');
        $this->api()->getJson("/api/restaurant/commandes/{$b['id']}")->assertJsonPath('data.statut', 'annulee');

        $ligne = $a['lignes'][1]['id'];
        $this->api()->deleteJson("/api/restaurant/commandes/{$a['id']}/lignes/{$ligne}")->assertOk()->assertJsonPath('data.total', 13000);
        $this->api()->postJson("/api/restaurant/commandes/{$a['id']}/annuler", ['motif' => 'client parti'])->assertOk()->assertJsonPath('data.statut', 'annulee');
    }

    public function test_reservation_puis_arrivee_ouvre_la_table(): void
    {
        $r = $this->api()->postJson('/api/restaurant/reservations', ['nom' => 'Famille Traoré', 'le' => now()->setTime(20, 0)->toIso8601String(), 'couverts' => 6, 'table' => 'Table 8'])
            ->assertCreated()->json('data');
        $this->api()->getJson('/api/restaurant/compteurs')->assertJsonPath('data.reservations', 1);
        $arrivee = $this->api()->postJson("/api/restaurant/reservations/{$r['id']}/arrivee")->assertOk();
        $this->api()->getJson('/api/restaurant/commandes/'.$arrivee->json('commande_id'))->assertJsonPath('data.table', 'Table 8')->assertJsonPath('data.couverts', 6);
        $this->api()->postJson('/api/restaurant/tables', ['nom' => 'Table 8', 'places' => 6])->assertCreated();
        $this->api()->getJson('/api/restaurant/tables')->assertJsonCount(1, 'data');
    }

    public function test_les_autres_boutiques_n_y_ont_pas_acces_et_gardent_leur_stock(): void
    {
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'commerce'])->assertOk();
        $this->commander()->assertUnprocessable();
        $this->api()->getJson('/api/restaurant/cuisine')->assertUnprocessable();
        // Un commerce refuse toujours une vente sans stock.
        $this->api()->postJson('/api/ventes', ['lignes' => [['produit_id' => $this->bissap, 'quantite' => 1]], 'moyen_paiement' => 'especes'])->assertUnprocessable();
    }

    public function test_categories_de_restaurant_et_bar_separe_de_la_cuisine(): void
    {
        $boissons = CategorieProduit::where('nom', 'Boissons')->firstOrFail();
        $this->assertSame(10, CategorieProduit::whereIn('nom', Boutique::CATEGORIES_RESTAURANT)->count());
        $this->api()->putJson("/api/produits/{$this->bissap}", ['categorie_produit_id' => $boissons->id])->assertOk();
        $this->api()->getJson('/api/restaurant/postes')->assertOk()->assertJsonFragment(['nom' => 'Boissons', 'poste' => 'bar']);

        $c = $this->commander()->json('data');
        $this->assertSame(['cuisine', 'bar'], array_column($c['lignes'], 'poste'));
        $bar = $this->api()->getJson('/api/restaurant/cuisine?poste=bar')->json('data');
        $this->assertSame(['Bissap'], array_column($bar[0]['lignes'], 'nom'));
        $cuisine = $this->api()->getJson('/api/restaurant/cuisine?poste=cuisine')->json('data');
        $this->assertSame(['Poulet braisé'], array_column($cuisine[0]['lignes'], 'nom'));

        // Repasser en restaurant ne recrée rien.
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'restaurant'])->assertOk();
        $this->assertSame(1, CategorieProduit::where('nom', 'Boissons')->count());
    }

    public function test_a_preparer_en_preparation_pret_et_le_serveur_est_prevenu(): void
    {
        $c = $this->commander()->json('data');
        $this->api()->postJson("/api/restaurant/commandes/{$c['id']}/commencer")->assertOk()
            ->assertJsonPath('data.lignes.0.etat', 'en_preparation');
        $this->api()->getJson('/api/restaurant/compteurs')->assertJsonPath('data.en_cuisine', 4);
        $this->api()->postJson("/api/restaurant/commandes/{$c['id']}/prets")->assertOk()->assertJsonPath('data.lignes.0.etat', 'prete');
        $notification = NotificationApp::where('user_id', $this->chef->id)->latest('id')->firstOrFail();
        $this->assertSame('Table 4 : commande prête', $notification->titre);
        $this->assertSame('/salle', $notification->lien);
    }

    public function test_options_a_choix_unique_et_obligatoire(): void
    {
        $normale = $this->api()->postJson("/api/restaurant/carte/{$this->poulet}/options", ['groupe' => 'Portion', 'nom' => 'Normale', 'choix_unique' => true, 'obligatoire' => true])->json('data.id');
        $grande = $this->api()->postJson("/api/restaurant/carte/{$this->poulet}/options", ['groupe' => 'Portion', 'nom' => 'Grande', 'prix' => 1000])->json('data.id');
        $carte = collect($this->api()->getJson('/api/restaurant/carte')->json('data'))->firstWhere('nom', 'Poulet braisé');
        $this->assertTrue($carte['options'][1]['choix_unique'], 'la règle du groupe vaut pour ses options');

        $plat = fn (array $options) => $this->api()->postJson('/api/restaurant/commandes', ['table' => 'T1', 'lignes' => [['produit_id' => $this->poulet, 'quantite' => 1, 'options' => $options]]]);
        $plat([])->assertUnprocessable();
        $plat([$normale, $grande])->assertUnprocessable();
        $plat([$grande])->assertCreated()->assertJsonPath('data.total', 4000);
    }

    public function test_paiement_mixte_especes_et_orange_money(): void
    {
        $this->api()->postJson('/api/sessions-caisse', ['fond_initial' => 10000])->assertCreated();
        $c = $this->commander()->json('data');
        $payer = fn (array $parts) => $this->api()->postJson("/api/restaurant/commandes/{$c['id']}/payer", ['paiements' => $parts]);
        $payer([['moyen' => 'especes', 'montant' => 4000], ['moyen' => 'orange_money', 'montant' => 2000]])->assertUnprocessable()->assertJsonValidationErrors('paiements');
        $payer([['moyen' => 'especes', 'montant' => 4000], ['moyen' => 'orange_money', 'montant' => 3000]])->assertOk()->assertJsonPath('data.statut', 'payee');

        $vente = Vente::sole();
        $this->assertSame(7000, (int) $vente->total);
        $this->assertCount(2, $vente->paiements);
        $this->api()->getJson('/api/sessions-caisse/courante')->assertJsonPath('fond_attendu', 14000);
        $jour = now()->toDateString();
        $parMoyen = collect($this->api()->getJson("/api/rapports?du={$jour}&au={$jour}")->json('par_moyen'))->pluck('total', 'moyen');
        $this->assertSame(4000, $parMoyen['especes']);
        $this->assertSame(3000, $parMoyen['orange_money']);
    }

    public function test_plan_de_salle_libre_occupee_a_payer_reservee(): void
    {
        foreach (['Table 1', 'Table 2', 'Table 3', 'Table 4'] as $nom) {
            $this->api()->postJson('/api/restaurant/tables', ['nom' => $nom, 'places' => 4])->assertCreated();
        }
        $occupee = $this->commander(['table' => 'Table 2'])->json('data');
        $servie = $this->commander(['table' => 'Table 3'])->json('data');
        $this->api()->postJson("/api/restaurant/commandes/{$servie['id']}/servir")->assertOk();
        $this->api()->postJson('/api/restaurant/reservations', ['nom' => 'Famille Koné', 'le' => now()->addHour()->toIso8601String(), 'table' => 'Table 4'])->assertCreated();
        $this->commander(['table' => 'Terrasse'])->assertCreated();

        $salle = collect($this->api()->getJson('/api/restaurant/salle')->assertOk()->json('data'))->keyBy('nom');
        $this->assertSame('libre', $salle['Table 1']['etat']);
        $this->assertSame('occupee', $salle['Table 2']['etat']);
        $this->assertSame($occupee['id'], $salle['Table 2']['commande_id']);
        $this->assertSame('a_payer', $salle['Table 3']['etat']);
        $this->assertSame('reservee', $salle['Table 4']['etat']);
        $this->assertSame('occupee', $salle['Terrasse']['etat'], 'une table tapée à la main apparaît aussi');
    }

    public function test_au_restaurant_rien_ne_se_vend_hors_d_une_commande(): void
    {
        $this->api()->postJson('/api/ventes', ['lignes' => [['produit_id' => $this->bissap, 'quantite' => 1]], 'moyen_paiement' => 'especes'])
            ->assertUnprocessable()->assertJsonValidationErrors('lignes');
        $this->assertSame(0, Vente::count());
        // L'addition d'une commande, elle, passe.
        $c = $this->commander()->json('data');
        $this->api()->postJson("/api/restaurant/commandes/{$c['id']}/payer", ['moyen_paiement' => 'especes'])->assertOk();
        $this->assertSame(1, Vente::count());
    }
}
