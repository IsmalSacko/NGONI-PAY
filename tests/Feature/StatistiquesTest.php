<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\CategorieProduit;
use App\Models\Client;
use App\Models\Produit;
use App\Models\SessionCaisse;
use App\Models\User;
use App\Models\Vente;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StatistiquesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Boutique $boutique;

    private Produit $riz;

    private Produit $huile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        ['user' => $this->admin, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        $this->dans();
        $alimentation = CategorieProduit::create(['nom' => 'Céréales', 'couleur' => '#E3A008']);
        $this->riz = Produit::create(['nom' => 'Riz', 'prix_vente' => 5000, 'prix_achat' => 4000, 'taux_tva' => 0, 'stock' => 100, 'categorie_produit_id' => $alimentation->id]);
        $this->huile = Produit::create(['nom' => 'Huile', 'prix_vente' => 1500, 'prix_achat' => 1450, 'taux_tva' => 0, 'stock' => 3]);
        // Les articles d'exemple de l'inscription dormiraient dans chaque test de stock.
        Produit::where('photo', 'like', '%-depart.png')->delete();
    }

    public function test_la_periode_se_compare_a_la_precedente_de_meme_duree(): void
    {
        $this->vendre([[$this->riz, 2]], ilYa: 0);   // 10 000 cette semaine
        $this->vendre([[$this->riz, 1]], ilYa: 3);   //  5 000 cette semaine
        $this->vendre([[$this->riz, 2]], ilYa: 8);   // 10 000 la semaine d'avant
        $this->vendre([[$this->riz, 9]], ilYa: 20);  // hors des deux

        $stats = $this->statistiques(du: now()->subDays(6), au: now());

        $this->assertSame(['du' => now()->subDays(13)->toDateString(), 'au' => now()->subDays(7)->toDateString()], $stats['comparaison']['precedente']);
        $this->assertSame(['actuel' => 15_000, 'precedent' => 10_000, 'variation' => 50], $stats['comparaison']['indicateurs']['chiffre_affaires']);
        $this->assertSame(['actuel' => 2, 'precedent' => 1, 'variation' => 100], $stats['comparaison']['indicateurs']['tickets']);
        $parJour = collect($stats['comparaison']['par_jour']);
        $this->assertCount(7, $parJour);
        $this->assertSame(['total' => 10_000, 'precedent' => 0], collect($parJour->last())->only('total', 'precedent')->all());
        // Le n-ième jour face au n-ième de la période précédente : la vente d'il y a 8 jours
        // (6e jour de la précédente) se retrouve en face d'hier (6e jour de celle-ci).
        $this->assertSame(10_000, $parJour->firstWhere('date', now()->subDay()->toDateString())['precedent']);
    }

    public function test_l_analyse_est_reservee_au_plan_qui_l_inclut(): void
    {
        $this->vendre([[$this->riz, 1]]);
        $this->assertTrue($this->statistiques()['analyse_disponible'], 'essai : tout est inclus');

        $exploitant = User::create(['name' => 'Ismael', 'phone' => '+33605758494', 'password' => 'password123']);
        app(AbonnementService::class)->accorder($this->admin, 'basic', now()->addMonth(), $exploitant);

        $stats = $this->statistiques();
        $this->assertFalse($stats['analyse_disponible']);
        $this->assertNull($stats['analyse']);
        $this->assertSame(5_000, $stats['rapport']['ventes']['total'], 'l’essentiel reste pour tous');
        $this->assertNotNull($stats['comparaison']);
    }

    public function test_l_affluence_se_lit_a_l_heure_du_pays(): void
    {
        $this->boutique->forceFill(['pays' => 'CM'])->save();   // Douala, UTC+1
        $vente = $this->vendre([[$this->riz, 1]]);
        Vente::withoutGlobalScopes()->whereKey($vente)->update(['created_at' => now()->startOfWeek()->addDays(2)->setTime(10, 30)]); // mercredi 10 h 30 UTC

        $affluence = $this->statistiques()['analyse']['affluence'];

        $this->assertSame('Africa/Douala', $affluence['fuseau']);
        $this->assertSame(1, $affluence['grille'][2][11], 'mercredi, 11 h à Douala');
        $this->assertSame([11, 3], [$affluence['heure_pointe'], $affluence['jour_pointe']]);
    }

    public function test_un_article_renomme_reste_un_seul_article_avec_sa_marge(): void
    {
        $this->vendre([[$this->riz, 2], [$this->huile, 2]]);
        $this->riz->update(['nom' => 'Riz parfumé 5 kg']);
        $this->vendre([[$this->riz, 1]]);

        $stats = $this->statistiques();
        $meilleurs = collect($stats['analyse']['produits']['meilleurs']);

        $this->assertSame(['Riz parfumé 5 kg', 'Huile'], $meilleurs->pluck('nom')->all());
        $this->assertEquals(['quantite' => 3, 'total' => 15_000, 'marge' => 3_000, 'taux_marge' => 20], collect($meilleurs->first())->only('quantite', 'total', 'marge', 'taux_marge')->all());
        $this->assertSame(['Huile'], collect($stats['analyse']['produits']['a_surveiller'])->pluck('nom')->all(), '3 % de marge');
        $this->assertSame('Riz parfumé 5 kg', $stats['rapport']['top_produits'][0]['nom'], 'le rapport aussi regroupe par article');
        $this->assertSame(3, $stats['rapport']['top_produits'][0]['quantite']);
        $this->assertSame(['Céréales', 'Sans catégorie'], collect($stats['analyse']['categories'])->pluck('nom')->all());
    }

    public function test_le_stock_qui_dort_et_celui_qui_va_manquer(): void
    {
        $savon = Produit::create(['nom' => 'Savon', 'prix_vente' => 500, 'prix_achat' => 300, 'taux_tva' => 0, 'stock' => 40]);
        $this->vendre([[$this->huile, 1]]);                     // 1 huile en 30 jours, 2 en stock : 60 j
        foreach (range(1, 10) as $_) {
            $this->vendre([[$this->riz, 3]]);                   // 30 riz en 30 jours, 70 en stock : 70 j
        }
        $this->dans();
        $this->huile->refresh()->update(['stock' => 0]);
        Produit::whereKey($this->riz->id)->update(['stock' => 5]);  // 5 riz, 1 par jour : 5 jours

        $stock = $this->statistiques()['analyse']['stock'];

        $this->assertSame(['Savon'], collect($stock['dormants'])->pluck('nom')->all());
        $this->assertSame(40 * 300, $stock['valeur_dormante'], 'au prix d’achat');
        $this->assertSame([['Riz', 5]], collect($stock['a_racheter'])->map(fn ($p) => [$p['nom'], $p['jours_restants']])->all());
        $this->assertNotNull($savon);
    }

    public function test_clients_fideles_nouveaux_et_credit(): void
    {
        $this->dans();
        $fatou = Client::create(['nom' => 'Fatou']);
        $binta = Client::create(['nom' => 'Binta']);
        $this->vendre([[$this->riz, 1]], client: $binta, ilYa: 40);            // Binta, cliente ancienne
        $this->vendre([[$this->riz, 1]], client: $binta);
        $this->vendre([[$this->riz, 2]], client: $fatou, moyen: 'credit_client');
        $this->vendre([[$this->riz, 1]], client: $fatou);
        $this->vendre([[$this->riz, 1]]);                                        // anonyme
        $this->api()->postJson("/api/clients/{$fatou->id}/reglements", ['montant' => 4000, 'moyen_paiement' => 'wave'])->assertCreated();

        $clients = $this->statistiques()['analyse']['clients'];

        $this->assertSame([2, 1, 1], [$clients['actifs'], $clients['nouveaux'], $clients['fideles']]);
        $this->assertSame(80, $clients['part_identifiee'], '20 000 sur 25 000');
        $this->assertSame(['Fatou', 'Binta'], collect($clients['meilleurs'])->pluck('nom')->all());
        $this->assertSame(6_000, $clients['credit']['encours']);
        $this->assertSame(['Fatou', 6_000, now()->toDateString()], [$clients['credit']['plus_gros'][0]['nom'], $clients['credit']['plus_gros'][0]['solde_du'], $clients['credit']['plus_gros'][0]['dernier_reglement']]);
    }

    public function test_l_equipe_ses_annulations_et_ses_ecarts(): void
    {
        $this->dans();
        $moussa = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Moussa', 'phone' => '+22370000002', 'password' => 'password123']);
        $moussa->assignRole('caissier');
        $this->vendre([[$this->riz, 2]], par: $moussa);
        $annulee = $this->vendre([[$this->riz, 1]], par: $moussa);
        $this->api()->postJson("/api/ventes/{$annulee}/annuler", ['motif' => 'Erreur'])->assertOk();
        $this->vendre([[$this->riz, 1]]);
        $this->dans();
        SessionCaisse::create(['user_id' => $moussa->id, 'fond_initial' => 5000, 'fond_final' => 4500, 'ecart' => -500, 'statut' => 'fermee', 'ouverte_le' => now()->subHours(3), 'fermee_le' => now()]);

        $equipe = collect($this->statistiques()['analyse']['equipe'])->keyBy('nom');

        $this->assertSame(['tickets' => 1, 'total' => 10_000, 'annulations' => 1, 'seances' => 1, 'ecart' => -500, 'manquant' => -500], collect($equipe['Moussa'])->only('tickets', 'total', 'annulations', 'seances', 'ecart', 'manquant')->all());
        $this->assertSame(['tickets' => 1, 'annulations' => 0, 'ecart' => null], collect($equipe['Awa'])->only('tickets', 'annulations', 'ecart')->all());
    }

    public function test_le_pilotage_compte_la_journee_d_affaires(): void
    {
        $this->vendre([[$this->riz, 1]]);
        $this->api()->postJson('/api/clotures')->assertCreated();
        $this->vendre([[$this->riz, 2]]);   // après la clôture : compte pour demain

        $this->api()->getJson('/api/dashboard')->assertOk()->assertJsonPath('ventes_jour.total', 10_000);
    }

    public function test_reserve_a_qui_voit_les_rapports(): void
    {
        $this->dans();
        $caissier = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Moussa', 'phone' => '+22370000002', 'password' => 'password123']);
        $caissier->assignRole('caissier');

        $this->api($caissier)->getJson('/api/statistiques')->assertForbidden();
        $this->api()->getJson('/api/statistiques?du=2025-01-01&au=2026-09-28')->assertStatus(422);
    }

    public function test_la_page_du_back_office_montre_l_analyse_ou_ce_que_le_pro_apporterait(): void
    {
        $this->vendre([[$this->riz, 2], [$this->huile, 1]]);
        $this->app['auth']->forgetGuards();

        $this->actingAs($this->admin)->get('/statistiques')->assertOk()
            ->assertSee('Chiffre d’affaires par jour')
            ->assertSee('Affluence')
            ->assertSee('Marge faible ou négative')
            ->assertDontSee('Allez plus loin dans vos chiffres');

        $exploitant = User::create(['name' => 'Ismael', 'phone' => '+33605758494', 'password' => 'password123']);
        app(AbonnementService::class)->accorder($this->admin, 'basic', now()->addMonth(), $exploitant);

        $this->actingAs($this->admin)->get('/statistiques?periode=7j')->assertOk()
            ->assertSee('Allez plus loin dans vos chiffres')
            ->assertDontSee('Stock qui dort');
    }

    // --- Aides ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private function statistiques(?\DateTimeInterface $du = null, ?\DateTimeInterface $au = null): array
    {
        $query = $du ? '?du='.$du->format('Y-m-d').'&au='.$au->format('Y-m-d') : '';

        return $this->api()->getJson('/api/statistiques'.$query)->assertOk()->json();
    }

    /** @param  list<array{Produit, int}>  $lignes */
    private function vendre(array $lignes, int $ilYa = 0, ?Client $client = null, string $moyen = 'especes', ?User $par = null): string
    {
        $id = $this->api($par)->postJson('/api/ventes', [
            'reference_locale' => (string) Str::uuid(),
            'lignes' => array_map(fn ($l) => ['produit_id' => $l[0]->id, 'quantite' => $l[1]], $lignes),
            'moyen_paiement' => $moyen,
            'client_id' => $client?->id,
        ])->assertCreated()->json('id');

        if ($ilYa > 0) {
            DB::table('ventes')->where('id', $id)->update(['created_at' => now()->subDays($ilYa), 'jour_affaire' => now()->subDays($ilYa)->toDateString()]);
        }

        return $id;
    }

    private function dans(): void
    {
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
    }

    private function api(?User $u = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken(($u ?? $this->admin)->createToken('t')->plainTextToken);
    }
}
