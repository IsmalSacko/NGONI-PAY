<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Boutiques\Index;
use App\Livewire\Plateforme\Comptes;
use App\Models\Boutique;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Services\RestaurationBoutique;
use App\Services\VenteService;
use App\Support\Tenancy\BoutiqueActive;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
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

    public function test_une_caisse_ouverte_est_signalee_puis_effacee_aussi(): void
    {
        DB::table('sessions_caisse')->where('boutique_id', $this->boutiqueAwa->id)->delete();
        DB::table('sessions_caisse')->insert([
            'id' => (string) Str::uuid(), 'boutique_id' => $this->boutiqueAwa->id, 'user_id' => $this->awa->id,
            'statut' => 'ouverte', 'ouverte_le' => now(), 'fond_initial' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken($this->jeton)->getJson("/api/plateforme/comptes/{$this->awa->id}/reinitialisation")->assertOk()
            ->assertJsonPath('boutiques.0.blocage', null)
            ->assertJsonPath('boutiques.0.avertissement', fn ($a) => str_contains((string) $a, 'caisse est encore ouverte'));

        $this->withToken($this->jeton)->postJson("/api/plateforme/boutiques/{$this->boutiqueAwa->id}/reinitialiser", ['confirmation' => 'REINITIALISER'])
            ->assertOk();
        $this->assertSame(0, $this->compter('ventes', $this->boutiqueAwa));
        $this->assertSame(0, $this->compter('sessions_caisse', $this->boutiqueAwa));
    }

    public function test_reserve_a_l_exploitant(): void
    {
        $this->withToken($this->awa->createToken('app')->plainTextToken)
            ->postJson("/api/plateforme/boutiques/{$this->boutiqueAwa->id}/reinitialiser", ['confirmation' => 'REINITIALISER'])
            ->assertForbidden();
    }

    public function test_depuis_la_console_web_apercu_puis_confirmation(): void
    {
        $exploitant = User::where('est_admin_plateforme', true)->first();

        Livewire::actingAs($exploitant)->test(Comptes::class)
            ->call('preparerReinitialisation', $this->boutiqueAwa->id)
            ->assertSee('Réinitialiser Pressing Awa')
            ->set('confirmation', 'oui')->call('reinitialiser')->assertHasErrors('confirmation')
            ->set('confirmation', 'REINITIALISER')->call('reinitialiser')->assertHasNoErrors()
            ->assertSet('aReinitialiser', null)->assertSee('remise à zéro')
            ->assertDispatched('toast', type: 'succes');

        $this->assertSame(0, $this->compter('ventes', $this->boutiqueAwa));
        $this->assertSame(1, $this->compter('ventes', $this->boutiqueIbrahim));
    }

    public function test_la_recherche_trouve_un_numero_tape_avec_espaces_ou_sans_indicatif(): void
    {
        DB::table('boutiques')->where('id', $this->boutiqueIbrahim->id)->update(['telephone' => '+2250707970437']);
        $this->awa->forceFill(['phone' => '+223707970437'])->save();

        $chercher = fn (string $q) => collect($this->withToken($this->jeton)->getJson('/api/plateforme/comptes?recherche='.urlencode($q))
            ->assertOk()->json('data'))->pluck('nom')->sort()->values()->all();

        // Numéro du compte, enregistré sans son zéro (mauvais pays à l'inscription).
        $this->assertContains('Awa', $chercher('0707970437'));
        // Numéro de la boutique, tapé comme on le dit.
        $this->assertContains('Ibrahim', $chercher('07 07 97 04 37'));
        $this->assertSame(['Ibrahim'], $chercher('76 00 82 02'));
        // Un nom ne passe pas par les chiffres.
        $this->assertSame(['Awa'], $chercher('awa'));
    }

    private function reinitialiser(bool $garderCatalogue): void
    {
        $this->withToken($this->jeton)->postJson("/api/plateforme/boutiques/{$this->boutiqueAwa->id}/reinitialiser", [
            'confirmation' => 'REINITIALISER', 'garder_catalogue' => $garderCatalogue,
        ])->assertOk();
    }

    public function test_la_restauration_remet_ventes_clients_stock_et_photos(): void
    {
        $avant = DB::table('produits')->where('boutique_id', $this->boutiqueAwa->id)->pluck('stock', 'id')->all();
        $photos = DB::table('produits')->where('boutique_id', $this->boutiqueAwa->id)->pluck('photo')->filter()->all();
        $this->assertNotEmpty($photos);

        $this->reinitialiser(garderCatalogue: false);
        foreach ($photos as $photo) {
            Storage::disk('local')->assertMissing($photo);
        }

        $restauration = app(RestaurationBoutique::class);
        [$chemin] = $restauration->sauvegardesParBoutique()[$this->boutiqueAwa->id];
        $restauration->restaurer($chemin, User::where('est_admin_plateforme', true)->first());

        $this->assertSame(1, $this->compter('ventes', $this->boutiqueAwa));
        $this->assertSame(1, $this->compter('clients', $this->boutiqueAwa));
        $this->assertSame($avant, DB::table('produits')->where('boutique_id', $this->boutiqueAwa->id)->pluck('stock', 'id')->all());
        $this->assertGreaterThan(0, DB::table('lignes_vente')->whereIn('vente_id', DB::table('ventes')->where('boutique_id', $this->boutiqueAwa->id)->select('id'))->count());
        foreach ($photos as $photo) {
            Storage::disk('local')->assertExists($photo);
        }

        // Une sauvegarde ne sert qu'une fois.
        $this->assertArrayNotHasKey($this->boutiqueAwa->id, $restauration->sauvegardesParBoutique());
    }

    public function test_les_articles_gardes_reprennent_leur_stock(): void
    {
        $avant = DB::table('produits')->where('boutique_id', $this->boutiqueAwa->id)->pluck('stock', 'id')->all();
        $this->reinitialiser(garderCatalogue: true);

        $restauration = app(RestaurationBoutique::class);
        $restauration->restaurer($restauration->sauvegardesParBoutique()[$this->boutiqueAwa->id][0], User::where('est_admin_plateforme', true)->first());

        $this->assertSame($avant, DB::table('produits')->where('boutique_id', $this->boutiqueAwa->id)->pluck('stock', 'id')->all());
        $this->assertSame(1, $this->compter('ventes', $this->boutiqueAwa));
    }

    public function test_les_ventes_faites_depuis_restent_et_les_anciennes_gardent_leur_numero(): void
    {
        $ancienne = DB::table('ventes')->where('boutique_id', $this->boutiqueAwa->id)->first();
        $avant = (int) DB::table('produits')->where('boutique_id', $this->boutiqueAwa->id)->sum('stock');
        $this->reinitialiser(garderCatalogue: true);
        $this->travel(1)->minutes();
        $this->vendre($this->boutiqueAwa, $this->awa);   // vraie vente : la numérotation continue, n° 2
        $nouvelle = DB::table('ventes')->where('boutique_id', $this->boutiqueAwa->id)->first();
        $apres = (int) DB::table('produits')->where('boutique_id', $this->boutiqueAwa->id)->sum('stock');

        $restauration = app(RestaurationBoutique::class);
        $restauration->restaurer($restauration->sauvegardesParBoutique()[$this->boutiqueAwa->id][0], User::where('est_admin_plateforme', true)->first());

        $ventes = DB::table('ventes')->where('boutique_id', $this->boutiqueAwa->id)->orderBy('numero')->get()->keyBy('id');
        $this->assertCount(2, $ventes);
        $this->assertSame(2, (int) $ventes[$nouvelle->id]->numero, 'la vraie vente continue la numérotation');
        $this->assertSame(1, (int) $ventes[$ancienne->id]->numero, 'l’ancienne revient avec son numéro');
        $this->assertSame($ancienne->numero_facture, $ventes[$ancienne->id]->numero_facture, 'et sa facture garde le même numéro');
        $this->assertNotSame($ventes[$ancienne->id]->numero_facture, $ventes[$nouvelle->id]->numero_facture);
        // Le stock d'avant revient en plus de ce qui a bougé depuis.
        $this->assertSame($avant + $apres, (int) DB::table('produits')->where('boutique_id', $this->boutiqueAwa->id)->sum('stock'));
    }

    public function test_apres_repartir_de_zero_aucun_numero_de_facture_ne_resert(): void
    {
        $this->vendre($this->boutiqueAwa, $this->awa);
        $remis = DB::table('ventes')->where('boutique_id', $this->boutiqueAwa->id)->pluck('numero_facture')->all();
        $this->assertCount(2, $remis);

        $this->reinitialiser(garderCatalogue: true);
        $this->vendre($this->boutiqueAwa, $this->awa);
        $this->vendre($this->boutiqueAwa, $this->awa);

        $nouveaux = DB::table('ventes')->where('boutique_id', $this->boutiqueAwa->id)->orderBy('numero')->pluck('numero')->map(fn ($n) => (int) $n)->all();
        $this->assertSame([3, 4], $nouveaux, 'la suite des numéros déjà remis');
        $this->assertEmpty(array_intersect($remis, DB::table('ventes')->where('boutique_id', $this->boutiqueAwa->id)->pluck('numero_facture')->all()));
        // L'autre boutique garde sa propre numérotation.
        $this->assertSame(1, (int) DB::table('ventes')->where('boutique_id', $this->boutiqueIbrahim->id)->value('numero'));
    }

    public function test_le_numero_de_facture_repart_a_1_chaque_annee_sans_jamais_resservir(): void
    {
        $this->vendre($this->boutiqueAwa, $this->awa);
        $annee = (int) now()->format('Y');
        $derniere = DB::table('ventes')->where('boutique_id', $this->boutiqueAwa->id)->orderByDesc('numero')->value('numero_facture');
        $this->assertSame("PA-{$annee}-0002", $derniere, 'l’année en cours continue');

        // Au 1er janvier, la facture repart à 1 ; l'année la distingue de celle d'avant.
        $this->travelTo(now()->addYear()->startOfYear()->addHours(9));
        $this->vendre($this->boutiqueAwa, $this->awa);
        $suivante = $annee + 1;
        $this->assertSame("PA-{$suivante}-0001", DB::table('ventes')->where('boutique_id', $this->boutiqueAwa->id)->orderByDesc('numero')->value('numero_facture'));

        // « Repartir de zéro » en cours d'année : la suite continue, rien ne resert.
        $this->reinitialiser(garderCatalogue: true);
        $this->vendre($this->boutiqueAwa, $this->awa);
        $this->assertSame("PA-{$suivante}-0002", DB::table('ventes')->where('boutique_id', $this->boutiqueAwa->id)->orderByDesc('numero')->value('numero_facture'));
    }

    public function test_renommer_la_boutique_ne_change_pas_le_numero_des_factures_remises(): void
    {
        $vente = Vente::withoutGlobalScopes()->where('boutique_id', $this->boutiqueAwa->id)->first();
        $remis = $vente->numero_facture;
        $this->assertStringStartsWith('PA-', $remis);

        $this->boutiqueAwa->forceFill(['nom' => 'Teinturerie Moderne'])->save();
        // Le préfixe est gardé le temps d'une requête : on repart d'un cache vide, comme une vraie requête.
        (fn () => self::$prefixes = [])->call(new Vente);

        $this->assertSame($remis, Vente::withoutGlobalScopes()->find($vente->id)->numero_facture, 'la facture déjà remise garde son numéro');
        $this->assertSame($remis, Vente::withoutGlobalScopes()->find($vente->id)->toArray()['numero_facture'], 'aussi dans ce que reçoit l’application');

        $this->vendre($this->boutiqueAwa, $this->awa);
        $nouvelle = Vente::withoutGlobalScopes()->where('boutique_id', $this->boutiqueAwa->id)->where('numero', 2)->first();
        $this->assertStringStartsWith('TM-', $nouvelle->numero_facture, 'les nouvelles prennent le nouveau nom');
    }

    public function test_une_vente_hors_ligne_renvoyee_depuis_n_est_pas_doublee(): void
    {
        $vente = (array) DB::table('ventes')->where('boutique_id', $this->boutiqueAwa->id)->first();
        $this->reinitialiser(garderCatalogue: true);

        // Le téléphone renvoie la même vente (même référence) : le serveur la recrée.
        DB::table('ventes')->insert([...$vente, 'id' => (string) Str::uuid()]);

        $restauration = app(RestaurationBoutique::class);
        $restauration->restaurer($restauration->sauvegardesParBoutique()[$this->boutiqueAwa->id][0], User::where('est_admin_plateforme', true)->first());

        $this->assertSame(1, $this->compter('ventes', $this->boutiqueAwa));
    }

    public function test_un_article_recree_avec_le_meme_code_barres_fusionne(): void
    {
        $ancien = DB::table('produits')->where('boutique_id', $this->boutiqueAwa->id)->first();
        DB::table('produits')->where('id', $ancien->id)->update(['code_barre' => '6111111111111', 'stock' => 7]);
        $this->reinitialiser(garderCatalogue: false);

        // Recréé depuis par le commerçant, même code-barres, 3 en stock.
        $actuel = (string) Str::uuid();
        DB::table('produits')->insert([
            'id' => $actuel, 'boutique_id' => $this->boutiqueAwa->id, 'nom' => 'Même article', 'code_barre' => '6111111111111',
            'prix_vente' => 500, 'stock' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $restauration = app(RestaurationBoutique::class);
        $restauration->restaurer($restauration->sauvegardesParBoutique()[$this->boutiqueAwa->id][0], User::where('est_admin_plateforme', true)->first());

        $this->assertNull(DB::table('produits')->find($ancien->id), 'pas de doublon');
        $this->assertSame(10, (int) DB::table('produits')->where('id', $actuel)->value('stock'));
        $this->assertTrue(DB::table('lignes_vente')->where('produit_id', $actuel)->exists(), 'l’ancienne vente pointe sur l’article actuel');
    }

    public function test_depuis_la_console_web_restaurer(): void
    {
        $this->reinitialiser(garderCatalogue: true);

        Livewire::actingAs(User::where('est_admin_plateforme', true)->first())->test(Comptes::class)
            ->assertSee('Restaurer')
            ->call('preparerRestauration', $this->boutiqueAwa->id)
            ->assertSee('Restaurer Pressing Awa')
            ->set('confirmationRestauration', 'oui')->call('restaurer')->assertHasErrors('confirmationRestauration')
            ->set('confirmationRestauration', 'RESTAURER')->call('restaurer')->assertHasNoErrors()
            ->assertSet('aRestaurer', null)->assertSee('est restaurée')
            ->assertDispatched('toast', type: 'succes');

        $this->assertSame(1, $this->compter('ventes', $this->boutiqueAwa));
    }

    public function test_les_sauvegardes_de_plus_de_30_jours_sont_effacees(): void
    {
        $this->reinitialiser(garderCatalogue: false);
        $restauration = app(RestaurationBoutique::class);
        $this->assertSame(0, $restauration->purger(), 'récente : gardée');

        $this->travel(31)->days();
        $this->artisan('ecaisse:purger-reinitialisations')->assertSuccessful();
        $this->assertSame([], Storage::disk('local')->allFiles('reinitialisations'));
    }

    public function test_une_caisse_ouverte_depuis_sans_vente_ne_bloque_pas(): void
    {
        // Une caisse ouverte au moment de la remise à zéro, et une autre ouverte depuis.
        DB::table('sessions_caisse')->insert([
            'id' => (string) Str::uuid(), 'boutique_id' => $this->boutiqueAwa->id, 'user_id' => $this->awa->id,
            'statut' => 'ouverte', 'ouverte_le' => now(), 'fond_initial' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->reinitialiser(garderCatalogue: true);
        $this->travel(1)->minutes();
        DB::table('sessions_caisse')->insert([
            'id' => (string) Str::uuid(), 'boutique_id' => $this->boutiqueAwa->id, 'user_id' => $this->awa->id,
            'statut' => 'ouverte', 'ouverte_le' => now(), 'fond_initial' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $restauration = app(RestaurationBoutique::class);
        $restauration->restaurer($restauration->sauvegardesParBoutique()[$this->boutiqueAwa->id][0], User::where('est_admin_plateforme', true)->first());

        $this->assertSame(1, $this->compter('ventes', $this->boutiqueAwa));
        $this->assertSame(1, DB::table('sessions_caisse')->where('boutique_id', $this->boutiqueAwa->id)->where('statut', 'ouverte')->count(), 'une seule caisse ouverte');
        $this->assertGreaterThan(0, DB::table('sessions_caisse')->where('boutique_id', $this->boutiqueAwa->id)->where('statut', 'fermee')->count());
    }

    public function test_le_proprietaire_reinitialise_sa_boutique_depuis_l_application(): void
    {
        $jeton = $this->awa->createToken('app')->plainTextToken;

        $this->withToken($jeton)->getJson('/api/boutique/reinitialisation')->assertOk()->assertJsonPath('ventes', 1);
        $this->withToken($jeton)->postJson('/api/boutique/reinitialiser', ['confirmation' => 'oui'])->assertUnprocessable();
        $this->withToken($jeton)->postJson('/api/boutique/reinitialiser', ['confirmation' => 'REINITIALISER'])->assertOk();

        $this->assertSame(0, $this->compter('ventes', $this->boutiqueAwa));
        $this->assertSame(1, $this->compter('ventes', $this->boutiqueIbrahim));
        // La restauration reste à l'exploitant.
        $this->withToken($jeton)->getJson("/api/plateforme/comptes/{$this->awa->id}/reinitialisation")->assertForbidden();
    }

    public function test_essai_termine_la_boutique_ne_se_reinitialise_plus(): void
    {
        app(AbonnementService::class)->revoquer($this->awa);
        $jeton = $this->awa->createToken('app')->plainTextToken;

        $this->withToken($jeton)->postJson('/api/boutique/reinitialiser', ['confirmation' => 'REINITIALISER'])
            ->assertForbidden()->assertJsonPath('code', 'ABONNEMENT_EXPIRE');
        $this->assertSame(1, $this->compter('ventes', $this->boutiqueAwa));
    }

    public function test_un_admin_qui_n_est_pas_proprietaire_ne_peut_pas(): void
    {
        app(TenantContext::class)->setBoutique($this->boutiqueAwa->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutiqueAwa->id);
        $adjoint = User::create(['boutique_id' => $this->boutiqueAwa->id, 'name' => 'Adjoint', 'phone' => '+22370000009', 'password' => 'password123']);
        $adjoint->assignRole('admin');
        $jeton = $adjoint->createToken('app')->plainTextToken;

        $this->withToken($jeton)->postJson('/api/boutique/reinitialiser', ['confirmation' => 'REINITIALISER'])->assertForbidden();
        $this->assertSame(1, $this->compter('ventes', $this->boutiqueAwa));
    }

    public function test_depuis_le_back_office_le_proprietaire_reinitialise(): void
    {
        session([BoutiqueActive::CLE_SESSION => $this->boutiqueAwa->id]);
        Livewire::actingAs($this->awa)->test(Index::class)
            ->assertSee('Réinitialiser la boutique')
            ->assertDontSee('Restaurer')
            ->call('preparerReinitialisation', $this->boutiqueAwa->id)
            ->assertSee('Réinitialiser Pressing Awa')
            ->set('confirmation', 'REINITIALISER')->call('reinitialiser')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'succes');

        $this->assertSame(0, $this->compter('ventes', $this->boutiqueAwa));
    }

    public function test_le_back_office_refuse_une_autre_boutique_que_la_sienne(): void
    {
        session([BoutiqueActive::CLE_SESSION => $this->boutiqueAwa->id]);
        Livewire::actingAs($this->awa)->test(Index::class)
            ->call('preparerReinitialisation', $this->boutiqueIbrahim->id)
            ->assertForbidden();
    }
}
