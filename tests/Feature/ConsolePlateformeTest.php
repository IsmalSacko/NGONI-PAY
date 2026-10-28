<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CycleFacturation;
use App\Livewire\Plateforme\Comptes;
use App\Livewire\Plateforme\Utilisateurs;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\Plan;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Services\RechercheDemandes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ConsolePlateformeTest extends TestCase
{
    use RefreshDatabase;

    private User $exploitant;

    private User $awa;

    private Boutique $boutique;

    /** La Console Ngoni (contrat v1) : c'est là que se règlent les formules. */
    private array $cle = ['Authorization' => 'Bearer cle-de-test-console', 'X-Console-Operateur' => 'Ismaila'];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        config(['services.console_ngoni.cle' => 'cle-de-test-console']);

        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);

        $this->exploitant = User::create(['name' => 'Ismael', 'phone' => '+33605758494', 'password' => 'password123']);
        $this->exploitant->forceFill(['est_admin_plateforme' => true])->save();
    }

    public function test_la_console_est_reservee_a_l_exploitant(): void
    {
        $this->get('/plateforme')->assertRedirect();
        $this->actingAs($this->awa)->get('/plateforme')->assertForbidden();

        foreach (['/plateforme', '/plateforme/comptes', '/plateforme/utilisateurs'] as $page) {
            $this->actingAs($this->exploitant)->get($page)->assertOk()
                ->assertSee('Abonnements : Console Ngoni')
                ->assertSee('https://console.ismaeldev.fr/demandes?app=ngoni-caisse', false);
        }
        // Demandes, abonnements payés, plans : dans la Console Ngoni seulement.
        foreach (['/plateforme/demandes', '/plateforme/abonnements', '/plateforme/plans'] as $page) {
            $this->actingAs($this->exploitant)->get($page)->assertNotFound();
        }
    }

    public function test_la_commande_donne_l_acces_a_la_console(): void
    {
        $this->artisan('ecaisse:admin-plateforme', ['telephone' => '76008201', '--pays' => 'ML'])->assertSuccessful();
        $this->assertTrue($this->awa->fresh()->est_admin_plateforme);
    }

    public function test_les_comptes_montrent_l_abonnement_sans_le_gerer(): void
    {
        $this->actingAs($this->exploitant);

        Livewire::test(Comptes::class)
            ->assertSee('Pressing Awa')
            ->assertSee('wa.me/22376008201')
            ->assertSee('essai')
            ->assertSee('Console Ngoni')
            ->assertDontSee('Révoquer')
            ->assertDontSee('Sans échéance');
    }

    public function test_une_demande_payee_a_vie_s_approuve_sans_echeance(): void
    {
        $demande = app(AbonnementService::class)->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Annuel);

        app(AbonnementService::class)->approuver($demande, $this->exploitant, sansEcheance: true);

        $abonnement = $this->awa->abonnement()->first();
        $this->assertSame('pro', $abonnement->plan);
        $this->assertNull($abonnement->fin);
        $this->assertTrue($abonnement->estEnCours());
    }

    public function test_les_demandes_se_rangent_par_onglet_et_se_retrouvent_par_recherche(): void
    {
        $service = app(AbonnementService::class);
        $approuvee = $service->soumettre($this->boutique, $this->awa, 'basic', CycleFacturation::Mensuel);
        $service->approuver($approuvee, $this->exploitant);
        $this->awa->abonnement()->update(['plan' => 'essai', 'fin' => now()->subDay()->toDateString()]);
        $enAttente = $service->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel);
        DemandeAbonnement::whereKey($enAttente->id)->update(['created_at' => now()->subDays(3)]);
        $recherche = app(RechercheDemandes::class);
        $ids = fn (string $onglet, string $terme = '') => $recherche->requete($onglet, $terme)->pluck('id')->all();

        // À traiter : seule la demande en attente ; l'onglet des approuvées, l'autre.
        $this->assertSame([$enAttente->id], $ids('en_attente'));
        $this->assertSame([$approuvee->id], $ids('approuvee'));

        // Recherche par boutique, par numéro (chiffres seuls) ou par nom.
        foreach (['pressing', '76 00 82 01', 'awa'] as $terme) {
            $this->assertCount(2, $ids('toutes', $terme));
        }
        $this->assertSame([], $ids('toutes', 'Boutique inconnue'));
    }

    public function test_les_plans_se_reglent_depuis_la_console_ngoni(): void
    {
        $this->patchJson('/api/console/v1/formules/basic', ['tarifs' => [['mois' => 1, 'montant' => 5000]], 'max_membres' => null], $this->cle)->assertOk();
        // L'essai reste proposé, même si l'on envoie le contraire.
        $this->patchJson('/api/console/v1/formules/essai', ['jours_essai' => 14, 'actif' => false], $this->cle)->assertOk();

        $basic = Plan::parCode('basic');
        $this->assertSame(5000, $basic->tarif(CycleFacturation::Mensuel)->montant);
        $this->assertNull($basic->max_membres);
        $this->assertSame(14, Plan::parCode('essai')->jours_essai);
        $this->assertTrue((bool) Plan::parCode('essai')->est_actif);
        $this->getJson('/api/plans')->assertJsonPath('data.1.tarifs.0.montant', 5000);

        // Un montant négatif est refusé.
        $this->patchJson('/api/console/v1/formules/basic', ['tarifs' => [['mois' => 1, 'montant' => -10]]], $this->cle)->assertStatus(422);
    }

    public function test_l_abonnement_a_vie_se_regle_depuis_la_console_ngoni_et_la_vitrine_suit(): void
    {
        $this->patchJson('/api/console/v1/formules/pro', [
            'tarifs' => [['mois' => 0, 'montant' => 300000]],
            'champs' => ['texte_a_vie' => 'Le Pro pour toujours.', 'a_vie_debut' => today()->subDay()->toDateString(), 'a_vie_fin' => today()->addMonth()->toDateString()],
        ], $this->cle)->assertOk();
        // Basic : plus proposé à vie.
        $this->patchJson('/api/console/v1/formules/basic', ['tarifs' => [['mois' => 0, 'montant' => null]]], $this->cle)->assertOk();

        $this->assertSame(300000, Plan::parCode('pro')->prix_a_vie);
        $this->assertNull(Plan::parCode('basic')->prix_a_vie);

        // La vitrine (aperçu de l'exploitant) et les conditions affichent le même prix.
        $this->actingAs($this->exploitant)->get('/?apercu=1')->assertOk()->assertSee('300 000')->assertSee('Le Pro pour toujours.')->assertDontSee('Basic à vie');
        $this->get('/conditions')->assertOk()->assertSee('Pro à vie (300 000 F CFA)', false);

        // Une fin avant le début est refusée.
        $this->patchJson('/api/console/v1/formules/pro', ['champs' => ['a_vie_fin' => today()->subMonth()->toDateString()]], $this->cle)->assertStatus(422);
    }

    public function test_hors_periode_l_abonnement_a_vie_disparait_de_la_vitrine(): void
    {
        Plan::query()->update(['a_vie_fin' => today()->subDay()]);

        $this->get('/')->assertOk()->assertDontSee('Abonnement à vie');
        $this->get('/conditions')->assertOk()->assertSee('n’est plus proposé à la souscription', false);
    }

    public function test_la_console_ngoni_coche_les_fonctions_d_un_plan(): void
    {
        $basic = Plan::parCode('basic');
        $pro = Plan::parCode('pro');
        $this->assertTrue($pro->inclut(Plan::SEANCES_CAISSE));

        $this->patchJson('/api/console/v1/formules/basic', ['champs' => ['fonctionnalites' => [...$basic->fonctionnalites, Plan::SEANCES_CAISSE]]], $this->cle)->assertOk();
        $this->patchJson('/api/console/v1/formules/pro', ['champs' => ['fonctionnalites' => array_values(array_diff($pro->fonctionnalites, [Plan::SEANCES_CAISSE]))]], $this->cle)->assertOk();

        $this->assertTrue(Plan::parCode('basic')->inclut(Plan::SEANCES_CAISSE));
        $this->assertFalse(Plan::parCode('pro')->inclut(Plan::SEANCES_CAISSE));
        $this->assertTrue(Plan::parCode('essai')->inclut(Plan::SEANCES_CAISSE));
    }

    public function test_l_api_donne_aux_comptes_et_utilisateurs_les_details_de_la_console_web(): void
    {
        $jeton = $this->exploitant->createToken('app')->plainTextToken;

        $compte = collect($this->withToken($jeton)->getJson('/api/plateforme/comptes')->assertOk()->json('data'))
            ->firstWhere('user_id', $this->awa->id);
        $this->assertSame($this->boutique->nom, $compte['boutiques_detail'][0]['nom']);
        $this->assertSame(0, $compte['boutiques_detail'][0]['ventes']);
        $this->assertSame(0, $compte['boutiques_detail'][0]['total_30j']);
        $this->assertArrayHasKey('parrain', $compte);
        $this->assertSame([$this->boutique->nom], $compte['boutiques'], 'l’ancien champ reste, pour les applications déjà installées');

        $utilisateur = collect($this->withToken($jeton)->getJson('/api/plateforme/utilisateurs?tri=nom')->assertOk()->json('data'))
            ->firstWhere('id', $this->awa->id);
        $this->assertSame(0, $utilisateur['ventes']);
        $this->assertStringContainsString('wa.me/', (string) $utilisateur['whatsapp']);
    }

    public function test_mot_de_passe_provisoire_pour_un_commercant_sans_email(): void
    {
        $this->awa->createToken('tablette');
        $this->actingAs($this->exploitant);

        $composant = Livewire::test(Utilisateurs::class)->call('motDePasse', $this->awa->id);
        $provisoire = $composant->get('motDePasseProvisoire');

        $this->assertMatchesRegularExpression('/^[a-z2-9]{10}$/', $provisoire);
        $this->assertTrue(Hash::check($provisoire, $this->awa->fresh()->password));
        $this->assertSame(0, $this->awa->tokens()->count());
        $composant->assertSee($provisoire)->assertSee('wa.me/22376008201');
    }

    public function test_la_console_montre_inscription_activite_et_usage(): void
    {
        $this->awa->createToken('app')->accessToken->forceFill(['last_used_at' => now()->subHours(2)])->save();

        $this->actingAs($this->exploitant)->get('/plateforme/utilisateurs')->assertOk()
            ->assertSee('Inscrit le '.$this->awa->created_at->format('d/m/Y'))
            ->assertSee('actif il y a 2 heures')
            ->assertSee('0 vente');

        $this->actingAs($this->exploitant)->get('/plateforme/comptes')->assertOk()
            ->assertSee('créée le')
            ->assertSee('actif dans l’app il y a 2 heures', false);
    }

    public function test_accorder_une_fonction_hors_formule_sans_toucher_a_celles_de_la_formule(): void
    {
        $basic = Plan::parCode('basic');
        $hors = collect(array_keys(Plan::FONCTIONNALITES))->first(fn (string $f) => ! $basic->inclut($f));
        $dedans = collect(array_keys(Plan::FONCTIONNALITES))->first(fn (string $f) => $basic->inclut($f));
        $service = app(AbonnementService::class);

        // Une fonction de la formule cochée en plus n'est pas gardée en double.
        $service->accorder($this->awa, 'basic', now()->addMonth(), $this->exploitant, fonctions: array_values(array_filter([$hors, $dedans])));

        $abonnement = $this->awa->abonnement()->first();
        $this->assertSame([$hors], $abonnement->fonctionsAccordees());
        $this->assertTrue($service->permet($this->boutique, $hors));
        $this->assertContains($hors, $abonnement->fonctionnalites());
        $this->actingAs($this->exploitant)->get('/plateforme/comptes')->assertOk()->assertSee('+ 1 fonction accordée');

        // L'application la voit incluse.
        $this->app['auth']->forgetGuards();
        $this->withToken($this->awa->createToken('t')->plainTextToken)
            ->withHeader('X-Boutique', $this->boutique->id)
            ->getJson('/api/abonnement')->assertOk()
            ->assertJsonFragment([$hors]);

        // Retirée : fermée de nouveau ; celles de la formule restent.
        $service->reglerFonctions($this->awa, []);
        $this->assertFalse($service->permet($this->boutique->fresh(), $hors));
        if ($dedans !== null) {
            $this->assertTrue($service->permet($this->boutique->fresh(), $dedans));
        }
    }
}
