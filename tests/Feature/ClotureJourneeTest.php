<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Rapports\Index;
use App\Models\Boutique;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ClotureJourneeTest extends TestCase
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

    public function test_la_numerotation_du_jour_repart_a_un_apres_la_cloture(): void
    {
        $a = $this->vendre($this->caissier);
        $b = $this->vendre($this->caissier);
        $this->assertSame([1, 2], Vente::withoutGlobalScopes()->whereIn('id', [$a, $b])->orderBy('numero')->pluck('numero_jour')->all());

        // Le caissier ne clôture pas.
        $this->api($this->caissier)->postJson('/api/clotures')->assertForbidden();

        $z = $this->api($this->admin)->postJson('/api/clotures')->assertCreated();
        $z->assertJsonPath('numero', 1)->assertJsonPath('totaux.ventes.nombre', 2)->assertJsonPath('totaux.ventes.total', 20000);

        // Après la clôture : la vente compte pour demain, numéro 1.
        $c = $this->vendre($this->caissier);
        $vente = Vente::withoutGlobalScopes()->find($c);
        $this->assertSame(1, $vente->numero_jour);
        $this->assertSame(today()->addDay()->toDateString(), $vente->jour_affaire->toDateString());
        $this->assertSame(3, $vente->numero);   // le numéro de facture continue

        $this->api($this->admin)->getJson('/api/journee')
            ->assertJsonPath('jour_affaire', today()->addDay()->toDateString())
            ->assertJsonPath('tickets', 1);

        // Le rapport d'aujourd'hui est figé : la vente d'après clôture n'y est pas.
        $this->api($this->admin)->getJson('/api/rapports')->assertJsonPath('ventes.nombre', 2);

        // Une journée clôturée ne se modifie plus.
        $this->api($this->admin)->postJson("/api/ventes/{$a}/annuler", ['motif' => 'Erreur'])->assertStatus(422);
        // La vente de la journée en cours, si.
        $this->api($this->admin)->postJson("/api/ventes/{$c}/annuler", ['motif' => 'Erreur'])->assertOk();

        // On ne clôture pas demain par avance.
        $this->api($this->admin)->postJson('/api/clotures')->assertStatus(422);
        $this->api($this->admin)->getJson('/api/clotures')->assertJsonPath('data.0.numero', 1);
    }

    public function test_le_lendemain_la_journee_reprend_normalement(): void
    {
        $this->vendre($this->caissier);
        $this->api($this->admin)->postJson('/api/clotures')->assertCreated();
        $this->travelTo(now()->addDays(2));
        $id = $this->vendre($this->caissier);
        $vente = Vente::withoutGlobalScopes()->find($id);
        $this->assertSame(today()->toDateString(), $vente->jour_affaire->toDateString());
        $this->assertSame(1, $vente->numero_jour);
        $this->api($this->admin)->postJson('/api/clotures')->assertCreated()->assertJsonPath('numero', 2);
    }

    public function test_cloture_depuis_le_back_office(): void
    {
        $this->vendre($this->caissier);
        $this->dans();
        $this->actingAs($this->admin);
        Livewire::test(Index::class)
            ->assertSee('Clôturer la journée')
            ->call('cloturer')
            ->assertSee('Z n° 1')
            ->assertSee('Les tickets repartent à 1');
        $this->get('/rapports/imprimer?du='.today()->toDateString().'&au='.today()->toDateString())->assertOk()->assertSee('Clôture Z n° 1');
    }
}
