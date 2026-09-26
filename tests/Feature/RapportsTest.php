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
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RapportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $caissier;

    private Boutique $boutique;

    private Produit $riz;

    protected function setUp(): void
    {
        parent::setUp();
        ['user' => $this->admin, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pharmacie', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        $this->dans();
        $this->riz = Produit::create(['nom' => 'Riz', 'prix_vente' => 5000, 'prix_achat' => 4000, 'taux_tva' => 0, 'stock' => 100]);
        $this->caissier = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Moussa', 'phone' => '+22370000002', 'password' => 'password123']);
        $this->caissier->assignRole('caissier');
    }

    private function dans(): void
    {
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
    }

    private function api(User $u)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($u->createToken('t')->plainTextToken);
    }

    private function vendre(User $u, array $extra = []): string
    {
        return $this->api($u)->postJson('/api/ventes', $extra + [
            'reference_locale' => (string) Str::uuid(),
            'lignes' => [['produit_id' => $this->riz->id, 'quantite' => 2]],
            'moyen_paiement' => 'especes',
        ])->assertCreated()->json('id');
    }

    private function preparer(): void
    {
        $this->vendre($this->admin, ['remise' => 1000]);                  // 9 000, espèces, Awa
        $this->dans();
        $fatou = Client::create(['nom' => 'Fatou']);
        $this->vendre($this->caissier, ['moyen_paiement' => 'credit_client', 'client_id' => $fatou->id]); // 10 000 crédit, Moussa
        $annulee = $this->vendre($this->caissier);                        // 10 000 puis annulée
        $this->api($this->admin)->postJson("/api/ventes/{$annulee}/annuler", ['motif' => 'Erreur'])->assertOk();
        $hier = $this->vendre($this->admin);                              // hier : hors période
        Vente::withoutGlobalScopes()->whereKey($hier)->update(['created_at' => now()->subDay()]);
        $this->api($this->admin)->postJson("/api/clients/{$fatou->id}/reglements", ['montant' => 3000, 'moyen_paiement' => 'wave'])->assertCreated();
    }

    public function test_la_cloture_du_jour_compte_juste(): void
    {
        $this->preparer();

        $r = $this->api($this->admin)->getJson('/api/rapports')->assertOk();
        $r->assertJsonPath('ventes.nombre', 2)
            ->assertJsonPath('ventes.total', 19000)
            ->assertJsonPath('ventes.remises', 1000)
            ->assertJsonPath('ventes.articles', 4)
            ->assertJsonPath('ventes.panier_moyen', 9500)
            ->assertJsonPath('annulees.nombre', 1)
            ->assertJsonPath('annulees.total', 10000)
            ->assertJsonPath('marge.cout', 16000)
            ->assertJsonPath('marge.marge', 4000)
            ->assertJsonPath('credit.accorde', 10000)
            ->assertJsonPath('credit.rembourse', 3000);

        $moyens = collect($r->json('par_moyen'))->pluck('total', 'moyen');
        $this->assertSame(10000, $moyens['credit_client']);
        $this->assertSame(9000, $moyens['especes']);
        $this->assertSame(['Moussa' => 10000, 'Awa' => 9000], collect($r->json('par_caissier'))->pluck('total', 'nom')->all());

        // Sur deux jours, la vente d'hier s'ajoute.
        $this->api($this->admin)->getJson('/api/rapports?du='.now()->subDay()->toDateString().'&au='.today()->toDateString())
            ->assertJsonPath('ventes.nombre', 3)->assertJsonCount(2, 'par_jour');

        $this->api($this->caissier)->getJson('/api/rapports')->assertForbidden();
    }

    public function test_back_office_rapport_exports_et_impression(): void
    {
        $this->preparer();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin);
        $this->dans();

        $this->get('/rapports')->assertOk()->assertSee('Chiffre d’affaires', false)->assertSee('19 000');
        $this->get('/rapports/imprimer?du='.today()->toDateString().'&au='.today()->toDateString())->assertOk()->assertSee('Rapport d’activité', false);

        $ventes = $this->get('/exports/ventes?du='.today()->toDateString().'&au='.today()->toDateString())->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $ventes);
        $this->assertStringContainsString('"N° facture";Statut', $ventes);
        $this->assertStringContainsString('Annulée', $ventes);
        $this->assertSame(3, substr_count(trim($ventes), "\n"));  // en-tête + 3 lignes du jour

        $this->assertStringContainsString('Riz;;;;94;', $this->get('/exports/stocks')->assertOk()->streamedContent());
        $this->assertStringContainsString('Fatou;;7000;XOF', $this->get('/exports/credits')->assertOk()->streamedContent());
    }

    public function test_la_marge_est_estimee_ou_expliquee(): void
    {
        $this->dans();
        $savon = Produit::create(['nom' => 'Savon', 'prix_vente' => 1000, 'taux_tva' => 0, 'stock' => 10]); // sans prix d'achat
        $this->api($this->admin)->postJson('/api/ventes', [
            'reference_locale' => (string) Str::uuid(),
            'lignes' => [['produit_id' => $savon->id, 'quantite' => 2], ['libelle' => 'Livraison', 'prix_unitaire' => 500, 'quantite' => 1]],
            'moyen_paiement' => 'especes',
        ])->assertCreated();

        $this->api($this->admin)->getJson('/api/rapports')->assertJsonPath('marge.taux', null);

        // Prix d'achat renseigné après la vente : la marge est estimée.
        $savon->update(['prix_achat' => 600]);
        $this->api($this->admin)->getJson('/api/rapports')
            ->assertJsonPath('marge.marge', 800)
            ->assertJsonPath('marge.taux', 40)
            ->assertJsonPath('marge.couverture', 80)
            ->assertJsonPath('marge.estimee', true);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin);
        $this->dans();
        $this->get('/rapports')->assertOk()->assertSee('Marge calculée sur 80 %', false)->assertSee('estimation');
    }
}
