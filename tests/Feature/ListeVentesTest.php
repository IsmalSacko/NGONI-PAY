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
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Liste des ventes longue : par pages, par période, avec recherche, et le
 * total de chaque jour sur toute la recherche.
 */
class ListeVentesTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Awa', 'pays' => 'ML', 'telephone' => '76008201', 'email' => null,
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

    private function vendre(string $produitId, int $quantite = 1, ?string $clientId = null): array
    {
        return $this->api()->postJson('/api/ventes', array_filter([
            'lignes' => [['produit_id' => $produitId, 'quantite' => $quantite]], 'moyen_paiement' => 'especes', 'client_id' => $clientId,
        ]))->assertCreated()->json();
    }

    public function test_des_dizaines_de_ventes_se_lisent_page_par_page_avec_le_total_du_jour(): void
    {
        $savon = Produit::create(['nom' => 'Savon', 'prix_vente' => 250, 'taux_tva' => 0, 'stock' => 1000]);
        $this->travelTo(now()->subDay()->setTime(10, 0));
        for ($i = 0; $i < 5; $i++) {
            $this->vendre($savon->id);
        }
        $this->travelBack();
        for ($i = 0; $i < 40; $i++) {
            $this->vendre($savon->id);
        }

        $page1 = $this->api()->getJson('/api/ventes')->assertOk();
        $this->assertCount(30, $page1->json('data'));
        $this->assertSame(45, $page1->json('total'));
        $this->assertSame(2, $page1->json('last_page'));
        $this->assertSame([['date' => today()->toDateString(), 'nombre' => 40, 'total' => 10000]], $page1->json('jours'));

        $page2 = $this->api()->getJson('/api/ventes?page=2')->assertOk();
        $this->assertCount(15, $page2->json('data'));
        $this->assertCount(2, $page2->json('jours'), 'la fin d’aujourd’hui, puis hier');

        $hier = $this->api()->getJson('/api/ventes?du='.today()->subDay()->toDateString().'&au='.today()->subDay()->toDateString())->assertOk();
        $this->assertSame(5, $hier->json('total'));
    }

    public function test_la_recherche_trouve_par_numero_client_ou_article(): void
    {
        $savon = Produit::create(['nom' => 'Savon Monsavon', 'prix_vente' => 250, 'taux_tva' => 0, 'stock' => 100]);
        $riz = Produit::create(['nom' => 'Riz parfumé', 'prix_vente' => 600, 'taux_tva' => 0, 'stock' => 100]);
        $fatou = Client::create(['nom' => 'Fatou Diarra', 'telephone' => '+22370112233']);

        $premiere = $this->vendre($savon->id);
        $this->vendre($riz->id, 2, $fatou->id);
        $this->vendre($savon->id);

        $this->assertSame(1, $this->api()->getJson('/api/ventes?recherche=riz')->json('total'));
        $this->assertSame(1, $this->api()->getJson('/api/ventes?recherche=Fatou')->json('total'));
        $this->assertSame(1, $this->api()->getJson('/api/ventes?recherche=70112233')->json('total'));
        $this->assertSame(2, $this->api()->getJson('/api/ventes?recherche=monsavon')->json('total'));
        $this->assertSame($premiere['id'], $this->api()->getJson('/api/ventes?recherche='.$premiere['numero_facture'])->json('data.0.id'));
        $this->assertSame(0, $this->api()->getJson('/api/ventes?recherche=inconnu')->json('total'));
    }

    public function test_une_vente_annulee_reste_listee_mais_ne_compte_pas_dans_le_jour(): void
    {
        $savon = Produit::create(['nom' => 'Savon', 'prix_vente' => 250, 'taux_tva' => 0, 'stock' => 100]);
        $this->vendre($savon->id);
        $annulee = $this->vendre($savon->id);
        $this->api()->postJson("/api/ventes/{$annulee['id']}/annuler", ['motif' => 'Erreur'])->assertOk();

        $liste = $this->api()->getJson('/api/ventes')->assertOk();
        $this->assertSame(2, $liste->json('total'));
        $this->assertSame(1, $liste->json('jours.0.nombre'));
        $this->assertSame(250, $liste->json('jours.0.total'));
        $this->assertSame(Vente::count(), 2);
    }
}
