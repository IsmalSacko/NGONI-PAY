<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\ServicePressing;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Mode pressing : des prestations (repassage, lavage…), pas de marchandise.
 * Rien à compter en stock : jamais de refus à la vente, jamais de rupture.
 */
class PressingTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

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
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($this->awa->createToken('t')->plainTextToken);
    }

    private function article(string $nom, int $prix, int $stock = 0): array
    {
        return $this->api()->postJson('/api/produits', ['nom' => $nom, 'prix_vente' => $prix, 'taux_tva' => 0, 'stock' => $stock])
            ->assertCreated()->json();
    }

    private function vendre(string $produitId, int $quantite)
    {
        return $this->api()->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $produitId, 'quantite' => $quantite]],
            'moyen_paiement' => 'especes',
        ]);
    }

    public function test_le_pressing_se_choisit_dans_les_reglages(): void
    {
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'pressing'])->assertOk()->assertJsonPath('data.activite', 'pressing');
        $this->api()->getJson('/api/moi')->assertOk()->assertJsonPath('user.boutique.activite', 'pressing');
    }

    public function test_une_prestation_se_vend_sans_stock_et_l_annulation_n_en_invente_pas(): void
    {
        $this->boutique->forceFill(['activite' => 'pressing'])->save();
        $chemise = $this->article('Chemise — repassage', 250);

        $vente = $this->vendre($chemise['id'], 5)->assertCreated();
        $this->assertSame(1250, $vente->json('total'));
        $this->assertEquals(0, Produit::find($chemise['id'])->stock, 'le stock ne bouge pas');
        $this->assertSame(0, MouvementStock::where('produit_id', $chemise['id'])->count(), 'aucun mouvement de stock');

        $this->api()->postJson('/api/ventes/'.$vente->json('id').'/annuler', ['motif' => 'Erreur de saisie'])->assertOk();
        $this->assertEquals(0, Produit::find($chemise['id'])->stock, 'l’annulation ne rend pas un stock jamais pris');
    }

    public function test_le_commerce_garde_son_stock_et_une_vente_d_avant_le_pressing_le_rend_a_l_annulation(): void
    {
        $savon = $this->article('Savon', 300, stock: 2);
        $this->vendre($savon['id'], 3)->assertUnprocessable();
        $vente = $this->vendre($savon['id'], 2)->assertCreated();
        $this->assertEquals(0, Produit::find($savon['id'])->stock);

        // La boutique passe en pressing : la vente d'avant avait pris du stock, il revient.
        $this->boutique->forceFill(['activite' => 'pressing'])->save();
        $this->api()->postJson('/api/ventes/'.$vente->json('id').'/annuler', ['motif' => 'Retour'])->assertOk();
        $this->assertEquals(2, Produit::find($savon['id'])->stock);
    }

    public function test_ni_rupture_ni_article_a_racheter(): void
    {
        $this->article('Pantalon — lavage', 500);
        $this->api()->getJson('/api/dashboard')->assertOk()->assertJsonPath('produits_en_rupture', 1);

        $this->boutique->forceFill(['activite' => 'pressing'])->save();
        $this->api()->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('produits_en_rupture', 0)
            ->assertJsonPath('produits_stock_faible', 0);
        $this->api()->getJson('/api/stocks/a-commander')->assertOk()->assertJsonPath('data', []);
    }

    /** @return array<string, string> nom du service → id */
    private function passerEnPressing(): array
    {
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'pressing'])->assertOk();

        return collect($this->api()->getJson('/api/services')->assertOk()->json('data'))->pluck('id', 'nom')->all();
    }

    public function test_le_passage_en_pressing_propose_les_services_courants_une_seule_fois(): void
    {
        $services = $this->passerEnPressing();
        $this->assertSame(ServicePressing::PAR_DEFAUT, array_keys($services));

        // Revenir en commerce puis en pressing ne les recrée pas.
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'commerce'])->assertOk();
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'pressing'])->assertOk();
        $this->assertSame(4, ServicePressing::count());
    }

    public function test_chaque_habit_a_son_prix_par_service_et_l_express_se_calcule_tout_seul(): void
    {
        $s = $this->passerEnPressing();
        $chemise = $this->api()->postJson('/api/produits', [
            'nom' => 'Chemise', 'prix_vente' => 300, 'taux_tva' => 0,
            'tarifs' => [
                ['service_id' => $s['Lavage + repassage'], 'prix' => 500],
                ['service_id' => $s['Repassage seul'], 'prix' => 300, 'prix_express' => 400],
            ],
        ])->assertCreated()->json();

        $depot = fn (bool $express) => $this->api()->postJson('/api/ventes', [
            'lignes' => [
                ['produit_id' => $chemise['id'], 'service_id' => $s['Lavage + repassage'], 'quantite' => 2],
                ['produit_id' => $chemise['id'], 'service_id' => $s['Repassage seul'], 'quantite' => 1],
            ],
            'express' => $express,
            'moyen_paiement' => 'especes',
        ])->assertCreated();

        $classique = $depot(false);
        $this->assertSame(2 * 500 + 300, $classique->json('total'));
        $this->assertSame(['Chemise · Lavage + repassage', 'Chemise · Repassage seul'], array_column($classique->json('lignes'), 'nom_produit'));
        $this->assertFalse($classique->json('express'));

        // Express : +50 % par défaut (500 → 750), sauf le prix express écrit sur l'habit (400).
        $express = $depot(true);
        $this->assertSame(2 * 750 + 400, $express->json('total'));
        $this->assertTrue($express->json('express'));
        $this->assertSame([true, true], array_column($express->json('lignes'), 'express'));

        // La boutique règle sa majoration : +100 %.
        $this->api()->putJson('/api/boutique/express', ['majoration_pct' => 100])->assertOk();
        $this->assertSame(2 * 1000 + 400, $depot(true)->json('total'));
    }

    public function test_un_habit_ne_se_vend_pas_dans_un_service_qu_il_n_a_pas(): void
    {
        $s = $this->passerEnPressing();
        $drap = $this->api()->postJson('/api/produits', [
            'nom' => 'Drap', 'prix_vente' => 750, 'taux_tva' => 0,
            'tarifs' => [['service_id' => $s['Lavage + repassage'], 'prix' => 750]],
        ])->assertCreated()->json();

        $this->api()->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $drap['id'], 'service_id' => $s['Nettoyage à sec'], 'quantite' => 1]],
            'moyen_paiement' => 'especes',
        ])->assertUnprocessable();
    }

    public function test_supprimer_un_service_retire_ses_prix_des_habits(): void
    {
        $s = $this->passerEnPressing();
        $bazin = $this->api()->postJson('/api/produits', [
            'nom' => 'Bazin homme', 'prix_vente' => 750, 'taux_tva' => 0,
            'tarifs' => [['service_id' => $s['Lavage + repassage'], 'prix' => 1250], ['service_id' => $s['Repassage seul'], 'prix' => 750]],
        ])->assertCreated()->json();

        $this->api()->putJson('/api/services/'.$s['Repassage seul'], ['nom' => 'Lavage + repassage'])->assertUnprocessable();
        $this->api()->putJson('/api/services/'.$s['Repassage seul'], ['nom' => 'Repassage'])->assertOk();
        $this->api()->deleteJson('/api/services/'.$s['Repassage seul'])->assertNoContent();
        $this->assertSame([['service_id' => $s['Lavage + repassage'], 'prix' => 1250]], Produit::find($bazin['id'])->tarifs);
    }
}
