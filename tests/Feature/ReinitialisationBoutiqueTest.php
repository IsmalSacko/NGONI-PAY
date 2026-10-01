<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Services\VenteService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReinitialisationBoutiqueTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutiqueAwa;

    private Boutique $boutiqueIbrahim;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');

        ['user' => $this->awa, 'boutique' => $this->boutiqueAwa] = $this->inscrire('Pressing Awa', '76008201', 'Awa');
        ['user' => $ibrahim, 'boutique' => $this->boutiqueIbrahim] = $this->inscrire('Quincaillerie Ibrahim', '76008202', 'Ibrahim');
        $this->vendre($this->boutiqueAwa, $this->awa);
        $this->vendre($this->boutiqueIbrahim, $ibrahim);
        DB::table('clients')->insert(['id' => (string) Str::uuid(), 'boutique_id' => $this->boutiqueAwa->id, 'nom' => 'Client test', 'created_at' => now(), 'updated_at' => now()]);

        $exploitant = User::create(['name' => 'Ismaila', 'phone' => '+22373136789', 'password' => 'password123']);
        $exploitant->forceFill(['est_admin_plateforme' => true])->save();
        $this->jeton = $exploitant->createToken('app')->plainTextToken;
    }

    private function inscrire(string $nom, string $tel, string $qui): array
    {
        return app(BoutiqueRegistrationService::class)->register([
            'nom' => $nom, 'pays' => 'ML', 'telephone' => $tel, 'email' => null, 'password' => 'password123', 'nom_utilisateur' => $qui,
        ]);
    }

    private function vendre(Boutique $boutique, User $vendeur): void
    {
        app(TenantContext::class)->setBoutique($boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($boutique->id);
        $produit = Produit::first();
        $produit->forceFill(['stock' => 10])->save();
        app(VenteService::class)->encaisser([
            'reference_locale' => (string) Str::uuid(),
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'moyen_paiement' => 'especes',
            'montant_recu' => $produit->prix_vente,
            'vendue_hors_ligne' => false,
        ], $vendeur);
    }

    private function compter(string $table, Boutique $boutique): int
    {
        return DB::table($table)->where('boutique_id', $boutique->id)->count();
    }

    public function test_l_apercu_compte_sans_rien_toucher(): void
    {
        $this->withToken($this->jeton)->getJson("/api/plateforme/comptes/{$this->awa->id}/reinitialisation")->assertOk()
            ->assertJsonPath('boutiques.0.nom', 'Pressing Awa')
            ->assertJsonPath('boutiques.0.ventes', 1)
            ->assertJsonPath('boutiques.0.clients', 1)
            ->assertJsonPath('boutiques.0.blocage', null);

        $this->assertSame(1, $this->compter('ventes', $this->boutiqueAwa));
    }

    public function test_il_faut_confirmer_et_le_catalogue_est_garde_stock_a_zero(): void
    {
        $url = "/api/plateforme/boutiques/{$this->boutiqueAwa->id}/reinitialiser";
        $this->withToken($this->jeton)->postJson($url, ['confirmation' => 'oui'])->assertUnprocessable();
        $this->assertSame(1, $this->compter('ventes', $this->boutiqueAwa));

        $articles = $this->compter('produits', $this->boutiqueAwa);
        $this->withToken($this->jeton)->postJson($url, ['confirmation' => 'REINITIALISER', 'garder_catalogue' => true])->assertOk();

        foreach (['ventes', 'mouvements_stock', 'sessions_caisse', 'clients'] as $table) {
            $this->assertSame(0, $this->compter($table, $this->boutiqueAwa), $table);
        }
        $this->assertSame($articles, $this->compter('produits', $this->boutiqueAwa));
        $this->assertSame(0, (int) DB::table('produits')->where('boutique_id', $this->boutiqueAwa->id)->sum('stock'));
        $this->assertNotNull(Boutique::withoutGlobalScopes()->find($this->boutiqueAwa->id));
        $this->assertNotNull(User::find($this->awa->id));

        // L'autre boutique n'est pas touchée.
        $this->assertSame(1, $this->compter('ventes', $this->boutiqueIbrahim));
        $this->assertCount(1, Storage::disk('local')->files('reinitialisations'));
    }

    public function test_sans_le_catalogue_articles_et_categories_partent(): void
    {
        $this->withToken($this->jeton)->postJson("/api/plateforme/boutiques/{$this->boutiqueAwa->id}/reinitialiser", [
            'confirmation' => 'REINITIALISER', 'garder_catalogue' => false, 'garder_fournisseurs' => false,
        ])->assertOk();

        $this->assertSame(0, $this->compter('produits', $this->boutiqueAwa));
        $this->assertSame(0, $this->compter('categories_produits', $this->boutiqueAwa));
        $this->assertGreaterThan(0, $this->compter('produits', $this->boutiqueIbrahim));
    }

    public function test_refuse_si_une_caisse_est_ouverte(): void
    {
        DB::table('sessions_caisse')->where('boutique_id', $this->boutiqueAwa->id)->delete();
        DB::table('sessions_caisse')->insert([
            'id' => (string) Str::uuid(), 'boutique_id' => $this->boutiqueAwa->id, 'user_id' => $this->awa->id,
            'statut' => 'ouverte', 'ouverte_le' => now(), 'fond_initial' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken($this->jeton)->postJson("/api/plateforme/boutiques/{$this->boutiqueAwa->id}/reinitialiser", ['confirmation' => 'REINITIALISER'])
            ->assertUnprocessable();
        $this->assertSame(1, $this->compter('ventes', $this->boutiqueAwa));
    }

    public function test_reserve_a_l_exploitant(): void
    {
        $this->withToken($this->awa->createToken('app')->plainTextToken)
            ->postJson("/api/plateforme/boutiques/{$this->boutiqueAwa->id}/reinitialiser", ['confirmation' => 'REINITIALISER'])
            ->assertForbidden();
    }
}
