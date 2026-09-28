<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Plateforme\EnLigne;
use App\Livewire\Plateforme\Tableau;
use App\Models\Boutique;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Services\Plateforme\Encaissements;
use App\Services\Plateforme\Presences;
use App\Support\Periode;
use App\Support\Presence\Appareil;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TableauPlateformeTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $pressing;

    private User $exploitant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->travelTo(now()->setTime(12, 0));

        ['user' => $this->awa, 'boutique' => $this->pressing] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        $this->exploitant = User::create(['name' => 'Ismael', 'phone' => '+33605758494', 'password' => 'password123']);
        $this->exploitant->forceFill(['est_admin_plateforme' => true])->save();
    }

    // --- Présence -----------------------------------------------------------

    public function test_une_requete_de_l_application_note_la_presence_et_l_appareil(): void
    {
        $jeton = $this->awa->createToken('app')->plainTextToken;

        $this->withToken($jeton)->withHeaders([
            Appareil::EN_TETE_PLATEFORME => 'android',
            Appareil::EN_TETE_MODELE => 'Samsung SM-A155F',
            Appareil::EN_TETE_VERSION => '4.4.0',
        ])->getJson('/api/moi')->assertOk()->assertJsonMissingPath('user.vu_le');

        $awa = $this->awa->fresh();
        $this->assertTrue($awa->vu_le->equalTo(now()));
        $this->assertSame(['android', 'Samsung SM-A155F', '4.4.0', $this->pressing->id], [$awa->vu_plateforme, $awa->vu_modele, $awa->vu_version, $awa->vu_boutique_id]);
    }

    public function test_la_presence_s_ecrit_au_plus_une_fois_par_minute_sans_toucher_updated_at(): void
    {
        $jeton = $this->awa->createToken('app')->plainTextToken;
        $modifieLe = $this->awa->fresh()->updated_at;
        // Comme en production, chaque requête relit l'utilisateur (le garde
        // d'authentification le garderait sinon en mémoire d'une requête à l'autre).
        $requete = function () use ($jeton): void {
            $this->app['auth']->forgetGuards();
            $this->withToken($jeton)->getJson('/api/moi')->assertOk();
        };

        $this->travel(10)->minutes();
        $requete();
        $premiere = $this->awa->fresh()->vu_le;

        $this->travel(30)->seconds();
        $requete();
        $this->assertTrue($this->awa->fresh()->vu_le->equalTo($premiere), 'moins d’une minute après : rien de réécrit');

        $this->travel(31)->seconds();
        $requete();
        $this->assertTrue($this->awa->fresh()->vu_le->gt($premiere));
        $this->assertTrue($this->awa->fresh()->updated_at->equalTo($modifieLe), 'être vu n’est pas modifier');
    }

    public function test_une_application_ancienne_ne_fait_pas_oublier_l_appareil_connu(): void
    {
        DB::table('users')->where('id', $this->awa->id)->update(['vu_plateforme' => 'android', 'vu_modele' => 'Tecno Spark 10']);

        $this->withToken($this->awa->createToken('app')->plainTextToken)
            ->withHeaders(['User-Agent' => 'Dart/3.13 (dart:io)'])
            ->getJson('/api/moi')->assertOk();

        $this->assertSame(['android', 'Tecno Spark 10'], [$this->awa->fresh()->vu_plateforme, $this->awa->fresh()->vu_modele]);
    }

    public function test_un_visiteur_non_connecte_n_ecrit_rien(): void
    {
        $this->get('/')->assertOk();

        $this->assertSame(0, User::whereNotNull('vu_le')->count());
    }

    /** @return iterable<string, array{array<string, string>, ?string, ?string}> */
    public static function requetes(): iterable
    {
        yield 'application à jour' => [['X-Appareil-Plateforme' => 'ios', 'X-Appareil-Modele' => 'iPhone 13'], 'ios', 'iPhone 13'];
        yield 'plateforme inconnue : le navigateur décide' => [['X-Appareil-Plateforme' => 'toaster', 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0 Safari/537.36'], 'web', 'Chrome · Windows'];
        yield 'back-office sur téléphone' => [['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36'], 'web', 'Chrome · Android'];
        yield 'Edge avant Chrome' => [['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/131.0 Safari/537.36 Edg/131.0'], 'web', 'Edge · Windows'];
        yield 'ancienne application : rien d’affirmé' => [['User-Agent' => 'Dart/3.13 (dart:io)'], null, null];
    }

    /** @param  array<string, string>  $entetes */
    #[DataProvider('requetes')]
    public function test_l_appareil_se_lit_dans_la_requete(array $entetes, ?string $plateforme, ?string $modele): void
    {
        $request = Request::create('/');
        $request->headers->replace(array_change_key_case($entetes));

        $appareil = Appareil::depuisRequete($request);

        $this->assertSame([$plateforme, $modele], [$appareil->plateforme, $appareil->modele]);
    }

    public function test_en_ligne_puis_il_y_a(): void
    {
        $this->assertSame('En ligne maintenant', Presences::depuis(now()->subMinutes(2)));
        $this->assertSame('En ligne il y a 12 min', Presences::depuis(now()->subMinutes(12)));
        $this->assertSame('En ligne il y a 1 h 05', Presences::depuis(now()->subMinutes(65)));
        $this->assertSame('En ligne il y a 3 j', Presences::depuis(now()->subDays(3)));
        $this->assertSame('Jamais vu', Presences::depuis(null));
    }

    public function test_les_derniers_vus_sans_les_comptes_de_l_exploitant(): void
    {
        $this->vu($this->awa, now()->subMinute(), 'android');
        $this->vu($this->exploitant, now(), 'web');
        $fanta = $this->commercant('Fanta', '76008202', now()->subHours(2), 'ios');

        $recents = app(Presences::class)->recents();

        $this->assertSame(['Awa', 'Fanta'], $recents->pluck('nom')->all());
        $this->assertSame([true, false], $recents->pluck('en_ligne')->all());
        $this->assertSame('ML', $recents->first()->pays);
        $this->assertSame(1, app(Presences::class)->nombreEnLigne());
        $this->assertSame(['android' => 1, 'ios' => 1], app(Presences::class)->parAppareil(Periode::depuis('7j'))->all());
        $this->assertNotNull($fanta);
    }

    // --- Encaissements -------------------------------------------------------

    public function test_les_montants_sont_ramenes_au_franc_cfa_quand_la_parite_est_fixe(): void
    {
        $paris = Boutique::create(['nom' => 'Épicerie Paris', 'pays' => 'FR', 'devise' => 'EUR']);
        $lagos = Boutique::create(['nom' => 'Lagos Market', 'pays' => 'NG', 'devise' => 'NGN']);
        $this->vente($this->pressing, 10_000);
        $this->vente($this->pressing, 5_000);
        $this->vente($paris, 1_000);            // 10,00 € = 6 560 F
        $this->vente($lagos, 250_000);          // 2 500,00 ₦ : pas de parité fixe
        $this->vente($this->pressing, 99_999, statut: 'annulee');
        $this->vente($this->pressing, 7_000, le: now()->subDays(20));

        $encaissements = app(Encaissements::class);
        $semaine = Periode::depuis('7j');

        $this->assertSame(15_000 + 6_560, $encaissements->totalFcfa($semaine));
        $this->assertSame(['NGN' => 250_000], $encaissements->horsFcfa($semaine));
        $this->assertSame(4, $encaissements->nombreDeVentes($semaine));
        $this->assertSame(['Pressing Awa', 'Épicerie Paris', 'Lagos Market'], $encaissements->parBoutique($semaine)->pluck('nom')->all());
        $this->assertSame(['ML' => 15_000, 'FR' => 6_560], $encaissements->parPays($semaine)->all());
        $this->assertSame(15_000 + 6_560 + 7_000, $encaissements->totalFcfa(Periode::depuis('30j')));
    }

    public function test_les_plus_actives_se_classent_au_nombre_de_ventes_avec_leur_variation(): void
    {
        $lagos = Boutique::create(['nom' => 'Lagos Market', 'pays' => 'NG', 'devise' => 'NGN']);
        foreach (range(1, 3) as $_) {
            $this->vente($lagos, 100);
        }
        $this->vente($this->pressing, 50_000);
        $this->vente($this->pressing, 50_000);
        $this->vente($lagos, 100, le: now()->subDays(9));
        $this->vente($lagos, 100, le: now()->subDays(10));

        $actives = app(Encaissements::class)->plusActives(Periode::depuis('7j'));

        $this->assertSame([['Lagos Market', 3, 50], ['Pressing Awa', 2, null]], $actives->map(fn ($b) => [$b->nom, $b->ventes, $b->variation])->all());
    }

    public function test_la_courbe_compte_chaque_jour_meme_sans_vente(): void
    {
        $this->vente($this->pressing, 4_000, le: now()->subDays(2));
        $this->vente($this->pressing, 1_000);

        $jours = app(Encaissements::class)->parJour(Periode::depuis('7j'));

        $this->assertCount(7, $jours);
        $this->assertSame([0, 0, 0, 0, 4_000, 0, 1_000], array_values($jours));
    }

    public function test_la_periode_precedente_a_la_meme_duree(): void
    {
        $semaine = Periode::depuis('7j');
        $avant = $semaine->precedente();

        $this->assertTrue($avant->fin->lt($semaine->debut));
        $this->assertEqualsWithDelta($semaine->debut->diffInSeconds($semaine->fin), $avant->debut->diffInSeconds($avant->fin), 2);
        $this->assertSame('7j', Periode::depuis('n’importe quoi')->code);
        $this->assertSame(27, Periode::variation(127, 100));
        $this->assertNull(Periode::variation(5, 0));
    }

    // --- Écran ---------------------------------------------------------------

    public function test_le_tableau_montre_boutiques_encaissements_et_presences(): void
    {
        $this->vu($this->awa, now()->subMinute(), 'android', 'Samsung SM-A155F');
        $this->vente($this->pressing, 12_500);

        $this->actingAs($this->exploitant)->get('/plateforme')->assertOk()
            ->assertSee('Encaissements par boutique')
            ->assertSee('Pressing Awa')
            ->assertSee('12 500 F');

        Livewire::actingAs($this->exploitant)->test(EnLigne::class)
            ->assertSee('1 en ligne')
            ->assertSee('En ligne maintenant')
            ->assertSee('Samsung SM-A155F');

        Livewire::actingAs($this->exploitant)->test(Tableau::class)
            ->call('$set', 'periode', 'jour')->assertSet('periode', 'jour')->assertSee('12 500 F')
            ->set('periode', 'inconnue')->assertSet('periode', '7j');
    }

    public function test_le_tableau_reste_reserve_a_l_exploitant(): void
    {
        $this->actingAs($this->awa)->get('/plateforme')->assertForbidden();
    }

    // --- Aides ---------------------------------------------------------------

    private function vente(Boutique $boutique, int $total, ?\DateTimeInterface $le = null, string $statut = 'validee'): void
    {
        static $numero = 0;
        $le ??= now();
        DB::table('ventes')->insert([
            'id' => (string) Str::uuid(), 'boutique_id' => $boutique->id, 'user_id' => $this->awa->id,
            'numero' => ++$numero, 'sous_total' => $total, 'total' => $total, 'moyen_paiement' => 'especes',
            'statut' => $statut, 'created_at' => $le, 'updated_at' => $le,
        ]);
    }

    private function vu(User $user, \DateTimeInterface $le, string $plateforme, ?string $modele = null): void
    {
        DB::table('users')->where('id', $user->id)->update(['vu_le' => $le, 'vu_plateforme' => $plateforme, 'vu_modele' => $modele]);
    }

    private function commercant(string $nom, string $telephone, \DateTimeInterface $vuLe, string $plateforme): User
    {
        ['user' => $user] = app(BoutiqueRegistrationService::class)->register([
            'nom' => "Boutique {$nom}", 'pays' => 'ML', 'telephone' => $telephone,
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => $nom,
        ]);
        $this->vu($user, $vuLe, $plateforme);

        return $user;
    }
}
