<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Coffres\Index as CoffresIndex;
use App\Models\Boutique;
use App\Models\Coffre;
use App\Models\MouvementCoffre;
use App\Models\NotificationApp;
use App\Models\Produit;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Services\Coffres;
use App\Services\Rapports;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le coffre : mettre de côté une partie de la recette, à la main ou chaque soir
 * (une somme par jour, ou une part des ventes encaissées). Offre Pro.
 */
class CoffresTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // Niger : UTC+1. À 22 h 30 UTC, il est 23 h 30 à Niamey : l'heure du versement.
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Awa', 'pays' => 'NE', 'telephone' => '90008201', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(AbonnementService::class)->accorder($this->awa, 'pro', now()->addMonth(), $this->awa);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        Produit::query()->delete();
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($this->awa->createToken('t')->plainTextToken);
    }

    private function vendre(int $montant): void
    {
        $article = $this->api()->postJson('/api/produits', ['nom' => 'Article '.$montant, 'prix_vente' => $montant, 'taux_tva' => 0, 'stock' => 10])->assertCreated()->json('id');
        $this->api()->postJson('/api/ventes', ['lignes' => [['produit_id' => $article, 'quantite' => 1]], 'moyen_paiement' => 'especes'])->assertCreated();
    }

    private function soir(): int
    {
        return app(Coffres::class)->passageDuSoir(Carbon::today()->setTime(22, 30));
    }

    public function test_chaque_soir_les_coffres_se_remplissent_sur_la_recette_du_jour(): void
    {
        $loyer = $this->api()->postJson('/api/coffres', ['nom' => 'Loyer', 'mode' => 'fixe', 'montant_fixe' => 2000])->assertCreated()->json('data.id');
        $tabaski = $this->api()->postJson('/api/coffres', ['nom' => 'Tabaski', 'mode' => 'pourcentage', 'pourcentage' => 5])->assertCreated()->json('data.id');
        $this->vendre(6000);
        $this->vendre(4000);

        // Le rappel de la fermeture de caisse, avant le soir.
        $this->api()->getJson('/api/coffres/aujourdhui')->assertOk()
            ->assertJsonPath('data.base', 10000)->assertJsonPath('data.total', 2500)
            ->assertJsonPath('data.coffres.0.montant', 2000)->assertJsonPath('data.coffres.1.montant', 500);

        $this->assertSame(1, $this->soir());
        $this->assertSame(0, $this->soir(), 'Un second passage ne verse rien de plus.');

        $coffres = $this->api()->getJson('/api/coffres')->assertOk();
        $this->assertSame(2500, $coffres->json('total'));
        $this->assertSame([2000, 500], array_column($coffres->json('data'), 'solde'));
        $this->assertSame(1, MouvementCoffre::where('coffre_id', $loyer)->count());
        $this->assertSame(10000, MouvementCoffre::where('coffre_id', $tabaski)->value('base'));

        $notification = NotificationApp::where('user_id', $this->awa->id)->where('type', Coffres::NOTIFICATION)->sole();
        $this->assertStringContainsString('Loyer', $notification->message);
        $this->assertStringContainsString('Pensez à les ranger', $notification->message);

        // Pilotage : Encaissé → Mis de côté → Reste disponible. Le chiffre ne bouge pas.
        app(TenantContext::class)->setBoutique($this->boutique->id);
        $rapport = app(Rapports::class)->periode(Carbon::today(), Carbon::today());
        $this->assertSame(10000, $rapport['encaisse']['total']);
        $this->assertSame(2500, $rapport['epargne']['mis_de_cote']);
        $this->assertSame('Loyer', $rapport['epargne']['par_coffre'][0]['nom']);
    }

    public function test_jamais_plus_que_la_recette_et_rien_un_jour_sans_vente(): void
    {
        $this->api()->postJson('/api/coffres', ['nom' => 'Loyer', 'mode' => 'fixe', 'montant_fixe' => 15000])->assertCreated();
        $this->api()->postJson('/api/coffres', ['nom' => 'Tabaski', 'mode' => 'fixe', 'montant_fixe' => 1000])->assertCreated();

        $this->assertSame(0, $this->soir(), 'Rien un jour sans vente.');
        $this->assertSame(0, MouvementCoffre::count());

        $this->vendre(10000);
        $this->soir();
        // Le premier coffre prend toute la recette ; le second, rien ce soir.
        $this->assertSame([10000], MouvementCoffre::orderBy('created_at')->pluck('montant')->all());
    }

    public function test_une_regle_ne_remonte_pas_avant_sa_journee(): void
    {
        $this->vendre(10000);
        $this->api()->postJson('/api/coffres', ['nom' => 'Loyer', 'mode' => 'fixe', 'montant_fixe' => 2000])->assertCreated();

        app(TenantContext::class)->setBoutique($this->boutique->id);
        $this->assertSame([], app(Coffres::class)->verser(Carbon::yesterday()), 'Pas de versement pour la veille de la règle.');
        $this->assertCount(1, app(Coffres::class)->verser(Carbon::today()));
    }

    public function test_ajouter_retirer_modifier_supprimer(): void
    {
        $id = $this->api()->postJson('/api/coffres', ['nom' => 'Loyer', 'mode' => 'manuel'])->assertCreated()->json('data.id');

        $this->api()->postJson("/api/coffres/{$id}/ajouter", ['montant' => 5000])->assertCreated()->assertJsonPath('data.solde', 5000);
        $this->api()->postJson("/api/coffres/{$id}/retirer", ['montant' => 6000])->assertUnprocessable()->assertJsonValidationErrors('montant');
        $this->api()->postJson("/api/coffres/{$id}/retirer", ['montant' => 1500])->assertCreated()->assertJsonPath('data.solde', 3500);

        // La règle change à tout moment ; seule la valeur de la règle choisie est gardée.
        $this->api()->putJson("/api/coffres/{$id}", ['nom' => 'Loyer du magasin', 'mode' => 'pourcentage', 'pourcentage' => 3, 'montant_fixe' => 999])
            ->assertOk()->assertJsonPath('data.mode', 'pourcentage')->assertJsonPath('data.montant_fixe', null)->assertJsonPath('data.solde', 3500);
        $this->api()->putJson("/api/coffres/{$id}", ['nom' => 'Loyer', 'mode' => 'fixe'])->assertUnprocessable()->assertJsonValidationErrors('montant_fixe');

        $this->api()->getJson("/api/coffres/{$id}/mouvements")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('solde', 3500);

        // Supprimer : seulement un coffre vide.
        $this->api()->deleteJson("/api/coffres/{$id}")->assertUnprocessable();
        $this->api()->postJson("/api/coffres/{$id}/retirer", ['montant' => 3500])->assertCreated();
        $this->api()->deleteJson("/api/coffres/{$id}")->assertOk();
        $this->assertSame(0, Coffre::withoutBoutiqueScope()->count());
    }

    public function test_le_basic_voit_le_cadenas(): void
    {
        app(AbonnementService::class)->accorder($this->awa, 'basic', now()->addMonth(), $this->awa);

        $this->api()->getJson('/api/coffres')->assertForbidden()
            ->assertJsonPath('code', 'FONCTIONNALITE_NON_INCLUSE')->assertJsonPath('fonctionnalite', 'coffre');
    }

    public function test_les_coffres_d_une_autre_boutique_restent_invisibles(): void
    {
        $id = $this->api()->postJson('/api/coffres', ['nom' => 'Loyer', 'mode' => 'manuel'])->assertCreated()->json('data.id');
        ['user' => $autre] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Ali', 'pays' => 'NE', 'telephone' => '90008202', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Ali',
        ]);
        app(AbonnementService::class)->accorder($autre, 'pro', now()->addMonth(), $autre);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $this->withToken($autre->createToken('t')->plainTextToken)->postJson("/api/coffres/{$id}/retirer", ['montant' => 1])->assertNotFound();
    }

    public function test_le_back_office_cree_un_coffre_ajoute_et_cache_les_montants(): void
    {
        $this->actingAs($this->awa);
        $page = Livewire::test(CoffresIndex::class)
            ->call('nouveau')->set('nom', 'Réserve')->set('mode', Coffre::POURCENTAGE)->set('pourcentage', '12,5')
            ->call('enregistrer')->assertHasNoErrors()->assertSet('edite', null);
        $coffre = Coffre::where('nom', 'Réserve')->firstOrFail();
        $this->assertSame(12.5, $coffre->pourcentage);

        $page->call('ouvrirMouvement', $coffre->id, true)->set('montant', '5000')->call('enregistrerMouvement')->assertHasNoErrors()
            // Caché tant que l'œil est fermé ; la règle, elle, se lit.
            ->assertSee('12,5 % des ventes encaissées')->assertDontSee('5 000')
            ->set('visible', true)->assertSee('5 000');
        $this->assertSame(5000, app(Coffres::class)->solde($coffre));

        // Jamais plus que le solde.
        $page->call('ouvrirMouvement', $coffre->id, false)->set('montant', '9000')->call('enregistrerMouvement')->assertHasErrors('montant');
    }

    public function test_sans_l_offre_pro_le_back_office_refuse_le_coffre(): void
    {
        app(AbonnementService::class)->accorder($this->awa, 'basic', now()->addMonth(), $this->awa);
        $this->actingAs($this->awa)->get('/coffre')->assertForbidden();
        Livewire::test(CoffresIndex::class)->call('nouveau')->assertForbidden();
    }
}
