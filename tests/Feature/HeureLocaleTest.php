<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\Ventes\Index as VentesIndex;
use App\Models\Boutique;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use App\Support\Fuseau;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les dates sont enregistrées en UTC ; le commerçant les lit à l'heure du
 * pays de sa boutique, sur le site, dans les PDF et dans l'export.
 */
class HeureLocaleTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Douala', 'pays' => 'CM', 'telephone' => '670000001', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
    }

    /** Une vente enregistrée à 18:30 UTC, soit 19:30 à Douala. */
    private function vendre(): Vente
    {
        $this->travelTo(Carbon::parse('2026-09-30 18:30:00', 'UTC'));
        app(TenantContext::class)->setBoutique($this->boutique->id);
        $produit = Produit::create(['nom' => 'Savon', 'prix_vente' => 500, 'taux_tva' => 0, 'stock' => 10]);
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $id = $this->withToken($this->awa->createToken('t')->plainTextToken)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]], 'moyen_paiement' => 'especes',
        ])->assertCreated()->json('id');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return Vente::withoutBoutiqueScope()->findOrFail($id);
    }

    public function test_le_fuseau_suit_le_pays(): void
    {
        $this->assertSame('Africa/Bamako', Fuseau::pourPays('ML'));
        $this->assertSame('Africa/Douala', Fuseau::pourPays('cm'));
        $this->assertSame('Europe/Paris', Fuseau::pourPays('FR'));
        $this->assertSame('Africa/Kinshasa', Fuseau::pourPays('CD'), 'la capitale, pour un pays à deux fuseaux');
        $this->assertSame('UTC', Fuseau::pourPays(null));
        $this->assertSame('UTC', Fuseau::pourPays('ZZ'));
        $this->assertSame('30/09/2026 20:30', Fuseau::heure(Carbon::parse('2026-09-30 18:30:00', 'UTC'), pays: 'FR'));
        $this->assertSame('', Fuseau::heure(null));
    }

    public function test_la_vente_s_affiche_a_l_heure_de_douala(): void
    {
        $vente = $this->vendre();
        $this->actingAs($this->awa);

        Livewire::test(VentesIndex::class)->call('voir', $vente->id)
            ->assertSee('30/09/2026 19:30')->assertDontSee('30/09/2026 18:30');
    }

    public function test_le_graphique_des_ventes_par_heure_est_a_l_heure_locale(): void
    {
        $this->vendre();
        $this->actingAs($this->awa);

        $parHeure = collect(Livewire::test(Dashboard::class)->viewData('ventesParHeure'))->pluck('total', 'heure');
        $this->assertSame(500, $parHeure[19]);
        $this->assertSame(0, $parHeure[18]);
    }

    public function test_l_export_donne_l_heure_locale_et_la_journee_locale(): void
    {
        $this->vendre();
        // Une vente à 23:30 UTC, déjà le 1er octobre à Douala : pas dans l'export du 30.
        $this->travelTo(Carbon::parse('2026-09-30 23:30:00', 'UTC'));
        app(TenantContext::class)->setBoutique($this->boutique->id);
        $autre = Produit::create(['nom' => 'Riz', 'prix_vente' => 700, 'taux_tva' => 0, 'stock' => 10]);
        app(TenantContext::class)->forget();
        $this->withToken($this->awa->createToken('t')->plainTextToken)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $autre->id, 'quantite' => 1]], 'moyen_paiement' => 'especes',
        ])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $csv = $this->actingAs($this->awa)->get('/exports/ventes?du=2026-09-30&au=2026-09-30')->assertOk()->streamedContent();

        $this->assertStringContainsString('30/09/2026;19:30', str_replace(',', ';', $csv));
        $this->assertStringNotContainsString('Riz', $csv, 'vendu le 1er octobre, heure de Douala');
    }
}
