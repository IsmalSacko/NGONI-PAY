<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Fournisseur;
use App\Models\Produit;
use App\Models\User;
use App\Services\AchatService;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les articles à recommander arrivent regroupés par le fournisseur de leur
 * dernier achat, avec une quantité qui couvre un mois de ventes.
 */
class CommandeFournisseurTest extends TestCase
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
        Produit::query()->delete(); // articles d'exemple de l'inscription
    }

    private function api(User $user)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($user->createToken('t')->plainTextToken);
    }

    public function test_les_articles_au_seuil_sont_groupes_par_dernier_fournisseur_avec_une_quantite(): void
    {
        $riz = Produit::create(['nom' => 'Riz 25 kg', 'prix_vente' => 15000, 'prix_achat' => 12000, 'taux_tva' => 0, 'stock' => 20, 'seuil_alerte' => 5]);
        $sucre = Produit::create(['nom' => 'Sucre 1 kg', 'prix_vente' => 800, 'taux_tva' => 0, 'stock' => 0, 'seuil_alerte' => 3]);
        Produit::create(['nom' => 'Savon', 'prix_vente' => 300, 'taux_tva' => 0, 'stock' => 40, 'seuil_alerte' => 5]);

        $ancien = Fournisseur::create(['nom' => 'Ancien grossiste', 'telephone' => '+22370000001']);
        $diallo = Fournisseur::create(['nom' => 'Grossiste Diallo', 'telephone' => '+22370000002']);
        $achats = app(AchatService::class);
        $achats->receptionner($this->awa, [['produit_id' => $riz->id, 'quantite' => 1, 'prix_achat' => 12000]], $ancien->id, null);
        $this->travel(1)->minutes();
        $achats->receptionner($this->awa, [['produit_id' => $riz->id, 'quantite' => 1, 'prix_achat' => 12000]], $diallo->id, null);

        // 18 riz vendus ce mois-ci : il en reste 4 (sous le seuil de 5).
        $this->api($this->awa)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $riz->id, 'quantite' => 18]], 'moyen_paiement' => 'especes',
        ])->assertCreated();
        $this->assertSame(4, $riz->fresh()->stock);

        $groupes = $this->api($this->awa)->getJson('/api/stocks/a-commander')->assertOk()->json('data');

        $this->assertCount(2, $groupes);
        $this->assertSame('Grossiste Diallo', $groupes[0]['fournisseur']['nom']);
        $this->assertSame('+22370000002', $groupes[0]['fournisseur']['telephone']);
        $this->assertSame([['produit_id' => $riz->id, 'nom' => 'Riz 25 kg', 'stock' => 4, 'seuil' => 5, 'quantite' => 14]], $groupes[0]['articles'], '18 vendus en un mois − 4 en stock');

        $this->assertNull($groupes[1]['fournisseur'], 'jamais acheté : sans fournisseur, en dernier');
        $this->assertSame(6, $groupes[1]['articles'][0]['quantite'], 'jamais vendu : deux fois le seuil');
        $this->assertSame($sucre->id, $groupes[1]['articles'][0]['produit_id']);
    }

    public function test_un_article_a_moins_d_une_semaine_de_stock_est_propose_comme_dans_le_pilotage(): void
    {
        // Seuil de 2, mais 50 vendus en un mois : les 10 restants tiennent 6 jours.
        $huile = Produit::create(['nom' => 'Huile 1 L', 'prix_vente' => 1500, 'taux_tva' => 0, 'stock' => 60, 'seuil_alerte' => 2]);
        $this->api($this->awa)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $huile->id, 'quantite' => 50]], 'moyen_paiement' => 'especes',
        ])->assertCreated();

        $groupes = $this->api($this->awa)->getJson('/api/stocks/a-commander')->assertOk()->json('data');

        $this->assertSame($huile->id, $groupes[0]['articles'][0]['produit_id']);
        $this->assertSame(40, $groupes[0]['articles'][0]['quantite'], 'un mois de ventes (50) − 10 en stock');

        // Le pilotage le range bien dans « À racheter » : les deux listes s'accordent.
        $jour = now()->toDateString();
        $aRacheter = $this->api($this->awa)->getJson("/api/statistiques?du={$jour}&au={$jour}")->json('analyse.stock.a_racheter');
        $this->assertContains($huile->id, array_column($aRacheter, 'produit_id'));
    }

    public function test_rien_a_commander_et_reserve_a_qui_achete(): void
    {
        Produit::create(['nom' => 'Savon', 'prix_vente' => 300, 'taux_tva' => 0, 'stock' => 40, 'seuil_alerte' => 5]);
        $this->api($this->awa)->getJson('/api/stocks/a-commander')->assertOk()->assertExactJson(['data' => []]);

        $caissier = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Caissier', 'phone' => '+22370000009', 'password' => 'password123']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        $caissier->assignRole('caissier');
        $this->api($caissier)->getJson('/api/stocks/a-commander')->assertForbidden();
    }
}
