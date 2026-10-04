<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Lot;
use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Mode pharmacie : vente au détail (boîte, plaquette, comprimé), lots et
 * péremption (premier périmé, premier sorti), ordonnance sur la vente.
 */
class PharmacieTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pharmacie du Fleuve', 'pays' => 'ML', 'telephone' => '76008201', 'email' => null,
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

    /** Doliprane 500 : 1 boîte = 2 plaquettes = 16 comprimés. */
    private function doliprane(int $stock = 0, ?string $peremption = null): array
    {
        return $this->api()->postJson('/api/produits', array_filter([
            'nom' => 'Doliprane 500 mg', 'dci' => 'Paracétamol', 'unite' => 'comprime', 'prix_vente' => 100, 'taux_tva' => 0,
            'stock' => $stock, 'peremption' => $peremption, 'numero_lot' => $peremption ? 'L-A' : null,
            'paliers' => [['unite' => 'boite', 'contenance' => 16, 'prix' => 1400], ['unite' => 'plaquette', 'contenance' => 8, 'prix' => 750]],
        ], fn ($v) => $v !== null))->assertCreated()->json();
    }

    public function test_l_activite_se_choisit_dans_les_reglages(): void
    {
        $this->assertSame('commerce', $this->boutique->fresh()->activite);
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'pharmacie'])->assertOk()->assertJsonPath('data.activite', 'pharmacie');
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'boulangerie'])->assertUnprocessable();
        $this->api()->getJson('/api/moi')->assertOk()->assertJsonPath('user.boutique.activite', 'pharmacie');
    }

    public function test_une_boite_une_plaquette_ou_des_comprimes_chacun_a_son_prix(): void
    {
        $p = $this->doliprane(stock: 48);
        $this->assertSame([['unite' => 'plaquette', 'contenance' => 8, 'prix' => 750], ['unite' => 'boite', 'contenance' => 16, 'prix' => 1400]], $p['paliers'], 'rangés du plus petit au plus grand');

        $vente = $this->api()->postJson('/api/ventes', [
            'lignes' => [
                ['produit_id' => $p['id'], 'quantite' => 1, 'palier' => 'boite'],
                ['produit_id' => $p['id'], 'quantite' => 1, 'palier' => 'plaquette'],
                ['produit_id' => $p['id'], 'quantite' => 3],
            ],
            'moyen_paiement' => 'especes',
        ])->assertCreated();

        $this->assertSame(1400 + 750 + 300, $vente->json('total'));
        $this->assertSame(['boite', 'plaquette', 'comprime'], array_column($vente->json('lignes'), 'unite'));
        $this->assertSame(48 - 16 - 8 - 3, Produit::find($p['id'])->stock);

        $this->api()->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $p['id'], 'quantite' => 1, 'palier' => 'flacon']], 'moyen_paiement' => 'especes',
        ])->assertUnprocessable();

        // Trois boîtes demandées sur deux lignes, il n'y a que 21 comprimés.
        $this->api()->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $p['id'], 'quantite' => 1, 'palier' => 'boite'], ['produit_id' => $p['id'], 'quantite' => 6]],
            'moyen_paiement' => 'especes',
        ])->assertUnprocessable();
    }

    public function test_le_lot_qui_perime_le_plus_tot_part_en_premier_et_revient_a_l_annulation(): void
    {
        $p = $this->doliprane(stock: 16, peremption: now()->addMonths(2)->toDateString());
        $this->api()->postJson('/api/achats', [
            'lignes' => [['produit_id' => $p['id'], 'quantite' => 2, 'palier' => 'boite', 'prix_achat' => 1000, 'numero_lot' => 'L-B', 'peremption' => now()->addYear()->toDateString()]],
            'montant_paye' => 2000,
        ])->assertCreated();

        $produit = Produit::find($p['id']);
        $this->assertSame(48, $produit->stock);
        $this->assertSame(63, $produit->prix_achat, 'prix d’achat d’un comprimé : 1 000 F / 16');

        $vente = $this->api()->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $p['id'], 'quantite' => 20]], 'moyen_paiement' => 'especes',
        ])->assertCreated()->json();

        $this->assertSame(0, Lot::where('numero', 'L-A')->first()->quantite, 'le lot qui périme dans 2 mois est vidé d’abord');
        $this->assertSame(28, Lot::where('numero', 'L-B')->first()->quantite);

        $this->api()->postJson("/api/ventes/{$vente['id']}/annuler", ['motif' => 'Erreur'])->assertOk();
        $this->assertSame(16, Lot::where('numero', 'L-A')->first()->quantite);
        $this->assertSame(32, Lot::where('numero', 'L-B')->first()->quantite);
        $this->assertSame(48, Produit::find($p['id'])->stock);
    }

    public function test_les_lots_qui_perimment_bientot_sont_signales(): void
    {
        $p = $this->doliprane(stock: 16, peremption: now()->addDays(20)->toDateString());
        $this->api()->getJson('/api/produits')->assertOk()->assertJsonPath('0.prochaine_peremption', now()->addDays(20)->toDateString());

        $alerte = $this->api()->getJson('/api/stocks/peremption')->assertOk()->json('data');
        $this->assertCount(1, $alerte);
        $this->assertSame(['nom' => 'Doliprane 500 mg', 'jours' => 20, 'quantite' => 16], [
            'nom' => $alerte[0]['nom'], 'jours' => $alerte[0]['jours'], 'quantite' => $alerte[0]['quantite'],
        ]);

        // Une casse sort du lot le plus proche.
        $this->api()->postJson("/api/produits/{$p['id']}/ajuster-stock", ['stock' => 10, 'motif' => 'Casse'])->assertOk();
        $this->assertSame(10, Lot::first()->quantite);
    }

    public function test_le_pilotage_compte_ce_qui_perime_et_la_commande_se_fait_en_boites(): void
    {
        $this->api()->getJson('/api/dashboard')->assertOk()->assertJsonMissingPath('a_perimer');
        $this->boutique->forceFill(['activite' => 'pharmacie'])->save();

        $p = $this->doliprane(stock: 16, peremption: now()->subDay()->toDateString());
        Produit::whereKey($p['id'])->update(['prix_achat' => 60, 'seuil_alerte' => 32]);
        $resume = $this->api()->getJson('/api/dashboard')->assertOk()->json('a_perimer');
        $this->assertSame(['lots' => 1, 'perimes' => 1, 'valeur' => 960], $resume);

        $commande = $this->api()->getJson('/api/stocks/a-commander')->assertOk()->json('data.0.articles.0');
        $this->assertSame(['quantite' => 3, 'unite' => 'boite'], $commande['commande'], '64 − 16 = 48 comprimés : 3 boîtes de 16');
    }

    public function test_l_ordonnance_reste_sur_la_vente_et_la_recherche_trouve_la_molecule(): void
    {
        $p = $this->doliprane(stock: 16);
        $vente = $this->api()->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $p['id'], 'quantite' => 8]], 'moyen_paiement' => 'especes',
            'ordonnance' => ['prescripteur' => 'Dr Traoré', 'numero' => 'ORD-12', 'patient' => ''],
        ])->assertCreated();
        $this->assertSame(['prescripteur' => 'Dr Traoré', 'numero' => 'ORD-12'], $vente->json('ordonnance'));

        $this->assertCount(1, $this->api()->getJson('/api/produits?recherche=parac')->json());
    }
}
