<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Client;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pressing : la commande naît au dépôt (numéro unique, prix figés, acompte),
 * devient prête, puis la vente naît au retrait. Les autres boutiques n'y ont pas accès.
 */
class CommandesPressingTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    /** @var array<string, string> */
    private array $services;

    private string $chemise;

    private string $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Awa', 'pays' => 'ML', 'telephone' => '76008201', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        Produit::query()->delete();
        $this->client = Client::create(['nom' => 'Moussa Diarra', 'telephone' => '+22370112233'])->id;

        $this->api()->putJson('/api/boutique/activite', ['activite' => 'pressing'])->assertOk();
        $this->services = collect($this->api()->getJson('/api/services')->json('data'))->pluck('id', 'nom')->all();
        $this->chemise = $this->api()->postJson('/api/produits', [
            'nom' => 'Chemise', 'prix_vente' => 400, 'taux_tva' => 0,
            'tarifs' => [
                ['service_id' => $this->services['Lavage + repassage'], 'prix' => 600, 'prix_express' => 1000],
                ['service_id' => $this->services['Repassage seul'], 'prix' => 400],
            ],
        ])->assertCreated()->json('id');
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($this->awa->createToken('t')->plainTextToken);
    }

    private function deposer(array $extra = [])
    {
        return $this->api()->postJson('/api/commandes-pressing', [
            'client_id' => $this->client,
            'lignes' => [
                ['produit_id' => $this->chemise, 'service_id' => $this->services['Lavage + repassage'], 'quantite' => 3, 'defauts' => 'tache au col'],
                ['produit_id' => $this->chemise, 'service_id' => $this->services['Repassage seul'], 'quantite' => 2],
            ],
            ...$extra,
        ]);
    }

    public function test_le_depot_cree_une_commande_numerotee_aux_prix_du_jour_sans_vente(): void
    {
        $commande = $this->deposer(['acompte' => 1000, 'moyen_acompte' => 'especes'])->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('/^[1-9]\d{5}$/', $commande['numero_lisible'], '6 chiffres au hasard');
        $this->assertSame(3 * 600 + 2 * 400, $commande['total']);
        $this->assertSame(1000, $commande['acompte']);
        $this->assertSame(1600, $commande['reste']);
        $this->assertSame('deposee', $commande['statut']);
        $this->assertSame('tache au col', $commande['lignes'][0]['defauts']);
        $this->assertNotNull($commande['retrait_prevu_le']);
        $this->assertSame(0, Vente::count(), 'pas de vente au dépôt');

        // Jamais deux fois le même numéro dans la boutique.
        $numeros = [$commande['numero'], ...array_map(fn () => $this->deposer()->assertCreated()->json('data.numero'), range(1, 20))];
        $this->assertCount(21, array_unique($numeros));
    }

    public function test_le_retrait_cree_la_vente_aux_prix_figes_acompte_compris(): void
    {
        $id = $this->deposer(['acompte' => 1000])->json('data.id');

        // Le tarif change après le dépôt : la commande garde ses prix.
        $this->api()->putJson("/api/produits/{$this->chemise}", [
            'tarifs' => [['service_id' => $this->services['Lavage + repassage'], 'prix' => 900], ['service_id' => $this->services['Repassage seul'], 'prix' => 500]],
        ])->assertOk();

        $this->api()->postJson("/api/commandes-pressing/{$id}/prete")->assertOk()->assertJsonPath('data.statut', 'prete');
        $retrait = $this->api()->postJson("/api/commandes-pressing/{$id}/retrait", ['moyen_paiement' => 'especes', 'montant_donne' => 2000])
            ->assertOk()->assertJsonPath('data.statut', 'retiree');

        $vente = Vente::with('lignes')->findOrFail($retrait->json('data.vente_id'));
        $this->assertSame(2600, $vente->total, 'prix du dépôt, pas les nouveaux');
        $this->assertSame($this->client, $vente->client_id);
        $this->assertSame(['Chemise · Lavage + repassage', 'Chemise · Repassage seul'], $vente->lignes->pluck('nom_produit')->all());

        // Clôturée : ni second retrait ni annulation.
        $this->api()->postJson("/api/commandes-pressing/{$id}/retrait", ['moyen_paiement' => 'especes'])->assertUnprocessable();
        $this->api()->postJson("/api/commandes-pressing/{$id}/annuler")->assertUnprocessable();
    }

    public function test_express_choisi_au_retrait_les_prix_suivent_le_tarif(): void
    {
        $id = $this->deposer(['acompte' => 1000])->json('data.id');
        $this->api()->postJson("/api/commandes-pressing/{$id}/prete")->assertOk();

        // Express : le prix express écrit (1 000), sinon le classique (400).
        $commande = $this->api()->postJson("/api/commandes-pressing/{$id}/express", ['express' => true])->assertOk()->json('data');
        $this->assertTrue($commande['express']);
        $this->assertSame(3 * 1000 + 2 * 400, $commande['total']);
        $this->assertSame(3800 - 1000, $commande['reste']);
        $this->assertSame([1000, 400], array_column($commande['lignes'], 'prix_unitaire'));

        // Retour au classique, puis la vente naît à ces prix.
        $this->api()->postJson("/api/commandes-pressing/{$id}/express", ['express' => false])->assertOk()->assertJsonPath('data.total', 2600);
        $this->api()->postJson("/api/commandes-pressing/{$id}/express", ['express' => true])->assertOk();
        $vente = $this->api()->postJson("/api/commandes-pressing/{$id}/retrait", ['moyen_paiement' => 'especes'])->assertOk()->json('data.vente_id');
        $this->assertSame(3800, Vente::findOrFail($vente)->total);
        $this->assertTrue((bool) Vente::findOrFail($vente)->express);

        // Retirée : on ne la change plus.
        $this->api()->postJson("/api/commandes-pressing/{$id}/express", ['express' => false])->assertUnprocessable();
    }

    public function test_recherche_par_numero_nom_telephone_et_filtres(): void
    {
        $this->deposer();
        ['id' => $id, 'numero' => $numero] = $this->deposer()->json('data');
        $this->api()->postJson("/api/commandes-pressing/{$id}/prete")->assertOk();

        $this->api()->getJson("/api/commandes-pressing?q={$numero}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.numero', $numero);
        $this->api()->getJson('/api/commandes-pressing?q=Moussa')->assertJsonCount(2, 'data');
        $this->api()->getJson('/api/commandes-pressing?q=70112233')->assertJsonCount(2, 'data');
        $this->api()->getJson('/api/commandes-pressing?filtre=pretes')->assertJsonCount(1, 'data');
        $this->api()->getJson('/api/commandes-pressing?filtre=en_cours')->assertJsonCount(1, 'data');

        $this->travel(5)->days();
        $this->api()->getJson('/api/commandes-pressing?filtre=retard')->assertJsonCount(2, 'data')->assertJsonPath('data.0.en_retard', true);
    }

    public function test_annuler_un_depot(): void
    {
        $id = $this->deposer()->json('data.id');

        $this->api()->postJson("/api/commandes-pressing/{$id}/annuler", ['motif' => 'Client a changé d’avis'])
            ->assertOk()->assertJsonPath('data.statut', 'annulee');
        $this->assertSame(0, Vente::count());
    }

    public function test_une_boutique_qui_n_est_pas_un_pressing_n_y_a_pas_acces(): void
    {
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'commerce'])->assertOk();

        $this->api()->getJson('/api/commandes-pressing')->assertUnprocessable();
        $this->deposer()->assertUnprocessable();
    }

    public function test_tout_le_pressing_est_dans_l_offre_basic(): void
    {
        $c = $this->deposer()->assertCreated()->json('data');
        $this->awa->abonnement()->update(['plan' => 'basic', 'fin' => now()->addMonth()->toDateString()]);

        $this->deposer(['express' => true])->assertCreated()->assertJsonPath('data.total', 3 * 1000 + 2 * 400);
        $this->deposer(['collecte' => true, 'adresse' => 'Badalabougou'])->assertCreated();
        $this->api()->postJson("/api/commandes-pressing/{$c['id']}/casier", ['casier' => 'A1'])->assertOk();
        $this->api()->post("/api/commandes-pressing/{$c['id']}/photos", ['photo' => UploadedFile::fake()->image('t.jpg')])->assertOk();
        $this->api()->postJson("/api/commandes-pressing/{$c['id']}/express", ['express' => true])->assertOk();
    }

    public function test_alertes_aujourdhui_retard_et_linge_abandonne(): void
    {
        // Midi : « aujourd'hui à 23 h » reste à venir (le soir, le test le comptait en retard).
        $this->travelTo(today()->setTime(12, 0));
        $this->deposer(['retrait_prevu_le' => now()->setTime(23, 0)->toIso8601String()])->assertCreated();
        $this->deposer(['retrait_prevu_le' => now()->subDays(2)->toIso8601String()])->assertCreated();
        $vieille = $this->deposer(['retrait_prevu_le' => now()->subDays(40)->toIso8601String()])->assertCreated()->json('data');
        $this->deposer()->assertCreated();
        $this->assertSame('Awa', $vieille['servi_par']);

        $this->api()->getJson('/api/commandes-pressing/compteurs')->assertOk()
            ->assertJson(['data' => ['aujourdhui' => 1, 'retard' => 2, 'abandon' => 1, 'pretes' => 0]]);
        $this->assertCount(1, $this->api()->getJson('/api/commandes-pressing?filtre=aujourdhui')->json('data'));
        $this->assertSame([$vieille['numero']], array_column($this->api()->getJson('/api/commandes-pressing?filtre=abandon')->json('data'), 'numero'));

        // Retirée, elle sort des alertes.
        $this->api()->postJson("/api/commandes-pressing/{$vieille['id']}/retrait", ['moyen_paiement' => 'especes'])->assertOk();
        $this->api()->getJson('/api/commandes-pressing/compteurs')->assertJson(['data' => ['abandon' => 0, 'retard' => 1]]);
    }

    public function test_etapes_du_travail_avec_historique(): void
    {
        $c = $this->deposer()->assertCreated()->json('data');
        $this->api()->postJson("/api/commandes-pressing/{$c['id']}/etape", ['etape' => 'lavage'])->assertOk()
            ->assertJsonPath('data.statut', 'en_traitement')->assertJsonPath('data.etape', 'lavage');
        $this->assertCount(1, $this->api()->getJson('/api/commandes-pressing?filtre=en_cours')->json('data'), 'au travail, elle reste en cours');
        $this->api()->postJson("/api/commandes-pressing/{$c['id']}/etape", ['etape' => 'repassage'])->assertOk();
        $this->api()->postJson("/api/commandes-pressing/{$c['id']}/etape", ['etape' => 'teinture'])->assertUnprocessable();
        $fini = $this->api()->postJson("/api/commandes-pressing/{$c['id']}/prete")->assertOk()->json('data');

        $this->assertSame(['deposee', 'lavage', 'repassage', 'prete'], array_column($fini['historique'], 'quoi'));
        $this->assertSame('Awa', $fini['historique'][1]['par']);
        $this->assertNull($fini['etape']);
    }

    public function test_acompte_compte_au_depot_pas_au_retrait(): void
    {
        $this->api()->postJson('/api/sessions-caisse', ['fond_initial' => 10000])->assertCreated();
        $c = $this->deposer(['acompte' => 1000, 'moyen_acompte' => 'especes'])->assertCreated()->json('data');
        $this->api()->getJson('/api/sessions-caisse/courante')->assertJsonPath('fond_attendu', 11000);

        $this->api()->postJson("/api/commandes-pressing/{$c['id']}/retrait", ['moyen_paiement' => 'especes', 'montant_donne' => 2000])->assertOk();
        $vente = Vente::firstOrFail();
        $this->assertSame(2600, (int) $vente->total);
        $this->assertSame(1000, (int) $vente->acompte_deduit);
        $this->assertSame(400, (int) $vente->monnaie_rendue);
        $this->api()->getJson('/api/sessions-caisse/courante')->assertJsonPath('fond_attendu', 12600);

        $jour = now()->toDateString();
        $this->api()->getJson("/api/rapports?du={$jour}&au={$jour}")->assertOk()
            ->assertJsonPath('encaisse.acomptes', 1000)->assertJsonPath('encaisse.ventes', 1600)->assertJsonPath('encaisse.total', 2600);
    }

    public function test_annulation_rembourse_l_acompte(): void
    {
        $this->api()->postJson('/api/sessions-caisse', ['fond_initial' => 10000])->assertCreated();
        $c = $this->deposer(['acompte' => 1000])->assertCreated()->json('data');
        $this->api()->postJson("/api/commandes-pressing/{$c['id']}/annuler", ['motif' => 'client parti', 'rembourser' => true])->assertOk()
            ->assertJsonPath('data.rembourse', 1000);
        $this->api()->getJson('/api/sessions-caisse/courante')->assertJsonPath('fond_attendu', 10000);
    }

    public function test_le_reste_peut_passer_en_credit_au_retrait(): void
    {
        $this->api()->postJson('/api/sessions-caisse', ['fond_initial' => 10000])->assertCreated();
        $c = $this->deposer(['acompte' => 1000])->assertCreated()->json('data');
        $this->api()->postJson("/api/commandes-pressing/{$c['id']}/retrait", ['moyen_paiement' => 'especes', 'credit' => true])->assertOk()
            ->assertJsonPath('data.statut', 'retiree');
        $vente = Vente::firstOrFail();
        $this->assertSame(1600, (int) $vente->reste_du);
        $this->assertSame($this->client, $vente->client_id);
        $this->api()->getJson('/api/sessions-caisse/courante')->assertJsonPath('fond_attendu', 11000);
    }

    public function test_conditions_du_recu_et_historique_du_client(): void
    {
        $this->api()->putJson('/api/commandes-pressing/reglages', ['conditions_depot' => 'Remboursement : 10 fois le prix du lavage.'])->assertOk()
            ->assertJsonPath('data.conditions_depot', 'Remboursement : 10 fois le prix du lavage.');
        $this->deposer()->assertCreated();
        $autre = Client::create(['nom' => 'Fanta', 'telephone' => '+22370000001'])->id;
        $this->assertCount(1, $this->api()->getJson("/api/commandes-pressing?client_id={$this->client}")->json('data'));
        $this->assertCount(0, $this->api()->getJson("/api/commandes-pressing?client_id={$autre}")->json('data'));
    }

    public function test_collecte_livraison_casier_et_photos(): void
    {
        Storage::fake('local');
        $this->deposer(['livraison' => true])->assertUnprocessable()->assertJsonValidationErrors('adresse');
        $c = $this->deposer(['collecte' => true, 'livraison' => true, 'adresse' => 'Hamdallaye ACI, rue 30'])->assertCreated()->json('data');
        $this->assertTrue($c['collecte']);
        $this->assertSame('Hamdallaye ACI, rue 30', $c['adresse']);

        $this->api()->post("/api/commandes-pressing/{$c['id']}/photos", ['photo' => UploadedFile::fake()->image('tache.jpg', 800, 600)])
            ->assertOk()->assertJsonPath('data.photos', 1);
        $this->api()->get("/api/commandes-pressing/{$c['id']}/photos/0")->assertOk();
        $this->api()->get("/api/commandes-pressing/{$c['id']}/photos/3")->assertNotFound();

        $this->api()->postJson("/api/commandes-pressing/{$c['id']}/prete", ['casier' => 'B12'])->assertOk()->assertJsonPath('data.casier', 'B12');
        $this->assertSame([$c['numero']], array_column($this->api()->getJson('/api/commandes-pressing?filtre=a_livrer')->json('data'), 'numero'));
        $this->assertCount(1, $this->api()->getJson('/api/commandes-pressing?q=B12')->json('data'), 'on retrouve le linge par son casier');

        $fini = $this->api()->postJson("/api/commandes-pressing/{$c['id']}/retrait", ['moyen_paiement' => 'especes'])->assertOk()->json('data');
        $this->assertSame('livree', end($fini['historique'])['quoi']);

        $this->api()->deleteJson("/api/commandes-pressing/{$c['id']}/photos/0")->assertOk()->assertJsonPath('data.photos', 0);
    }
}
