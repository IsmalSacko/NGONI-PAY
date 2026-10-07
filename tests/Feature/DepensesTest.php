<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Services\SessionCaisseService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Dépenses et bilan : toute boutique, toute offre (ici un commerce en essai). */
class DepensesTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Awa', 'pays' => 'NE', 'telephone' => '90008201', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
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

    public function test_un_commerce_note_ses_depenses_et_voit_son_bilan(): void
    {
        $savon = $this->api()->postJson('/api/produits', ['nom' => 'Savon', 'prix_vente' => 1000, 'taux_tva' => 0, 'stock' => 10])->assertCreated()->json();
        $this->api()->postJson('/api/ventes', ['lignes' => [['produit_id' => $savon['id'], 'quantite' => 3]], 'moyen_paiement' => 'especes'])->assertCreated();

        $this->api()->postJson('/api/depenses', ['libelle' => 'Loyer d’octobre', 'categorie' => 'loyer', 'montant' => 1500])->assertCreated();
        $this->api()->postJson('/api/depenses', ['libelle' => 'Crédit téléphone', 'categorie' => 'communication', 'montant' => 200])->assertCreated();
        $this->api()->postJson('/api/depenses', ['libelle' => 'Ménage', 'categorie' => 'inconnue', 'montant' => 100])->assertUnprocessable();

        $liste = $this->api()->getJson('/api/depenses')->assertOk();
        $this->assertSame(1700, $liste->json('total'));
        $this->assertArrayHasKey('taxes', $liste->json('categories'));

        $jour = now()->toDateString();
        $bilan = $this->api()->getJson("/api/bilan?du={$jour}&au={$jour}")->assertOk()->json('data');
        $this->assertSame(3000, $bilan['recettes']['total']);
        $this->assertSame(1700, $bilan['depenses']['total']);
        $this->assertSame(['loyer', 'communication'], array_column($bilan['depenses']['par_categorie'], 'categorie'));
        $this->assertSame(1300, $bilan['resultat']);

        // Supprimée : elle quitte le bilan.
        $id = $liste->json('data.0.id');
        $this->api()->deleteJson("/api/depenses/{$id}")->assertOk();
        $this->api()->getJson('/api/depenses')->assertJsonCount(1, 'data');
    }

    public function test_toute_depense_sort_de_la_caisse_quel_que_soit_le_moyen(): void
    {
        $session = app(SessionCaisseService::class)->ouvrir($this->awa, 10000);
        $this->api()->postJson('/api/depenses', ['libelle' => 'Électricité', 'categorie' => 'energie', 'montant' => 3000, 'moyen_paiement' => 'orange_money'])->assertCreated();
        $this->api()->postJson('/api/depenses', ['libelle' => 'Taxi', 'categorie' => 'transport', 'montant' => 500])->assertCreated();

        // Payée par Orange Money, son équivalent est repris dans le tiroir.
        $this->assertSame(10000 - 3000 - 500, app(SessionCaisseService::class)->fondAttendu($session->fresh()));

        // Le loyer payé hier se note à sa date ; demain, non.
        $hier = now()->subDay()->toDateString();
        $this->api()->postJson('/api/depenses', ['libelle' => 'Loyer', 'categorie' => 'loyer', 'montant' => 1000, 'jour' => $hier])->assertCreated();
        $this->api()->postJson('/api/depenses', ['libelle' => 'Avance', 'categorie' => 'autre', 'montant' => 1000, 'jour' => now()->addDay()->toDateString()])->assertUnprocessable();
    }
}
