<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CycleFacturation;
use App\Enums\StatutDemande;
use App\Livewire\Plateforme\Comptes;
use App\Livewire\Plateforme\Demandes;
use App\Livewire\Plateforme\Plans;
use App\Livewire\Plateforme\Utilisateurs;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\Plan;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');

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

        foreach (['/plateforme', '/plateforme/comptes', '/plateforme/demandes', '/plateforme/plans', '/plateforme/utilisateurs'] as $page) {
            $this->actingAs($this->exploitant)->get($page)->assertOk();
        }
    }

    public function test_la_commande_donne_l_acces_a_la_console(): void
    {
        $this->artisan('ecaisse:admin-plateforme', ['telephone' => '76008201', '--pays' => 'ML'])->assertSuccessful();
        $this->assertTrue($this->awa->fresh()->est_admin_plateforme);
    }

    public function test_accorder_puis_revoquer_un_abonnement(): void
    {
        $this->actingAs($this->exploitant);

        Livewire::test(Comptes::class)
            ->assertSee('Pressing Awa')
            ->assertSee('wa.me/22376008201')
            ->call('gerer', $this->awa->id)
            ->set('plan', 'pro')
            ->set('fin', now()->addYear()->toDateString())
            ->call('accorder')
            ->assertHasNoErrors();

        $abonnement = $this->awa->abonnement()->first();
        $this->assertSame('pro', $abonnement->plan);
        $this->assertTrue($abonnement->est_manuel);
        $this->assertSame(now()->addYear()->toDateString(), $abonnement->fin->toDateString());

        Livewire::test(Comptes::class)->call('revoquer', $this->awa->id)
            ->assertSee('expiré le '.now()->subDay()->format('d/m/Y'));
        $this->assertFalse($this->awa->abonnement()->first()->estEnCours());
    }

    public function test_approuver_ou_refuser_une_demande_avec_sa_preuve(): void
    {
        $service = app(AbonnementService::class);
        $demande = $service->soumettre($this->boutique, $this->awa, 'basic', CycleFacturation::Mensuel,
            preuve: UploadedFile::fake()->image('recu.jpg'));

        $this->actingAs($this->awa)->get(route('plateforme.preuve', $demande))->assertForbidden();
        $this->actingAs($this->exploitant)->get(route('plateforme.preuve', $demande))->assertOk();

        Livewire::test(Demandes::class)
            ->assertSee('Voir la preuve de paiement')
            ->call('approuver', $demande->id)
            ->assertSee('Demande approuvée');
        $this->assertSame('basic', $this->awa->abonnement()->first()->plan);

        // Refus : motif obligatoire, visible par le commerçant.
        $this->awa->abonnement()->update(['plan' => 'essai', 'fin' => now()->subDay()->toDateString()]);
        $autre = $service->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel);
        Livewire::test(Demandes::class)
            ->call('demanderRefus', $autre->id)->call('refuser')->assertHasErrors('motif')
            ->set('motif', 'Paiement introuvable')->call('refuser')->assertHasNoErrors();
        $this->assertSame(StatutDemande::Refusee, $autre->fresh()->statut);
    }

    public function test_les_demandes_se_rangent_par_onglet_et_se_retrouvent_par_recherche(): void
    {
        $service = app(AbonnementService::class);
        $approuvee = $service->soumettre($this->boutique, $this->awa, 'basic', CycleFacturation::Mensuel);
        $service->approuver($approuvee, $this->exploitant);
        $this->awa->abonnement()->update(['plan' => 'essai', 'fin' => now()->subDay()->toDateString()]);
        $enAttente = $service->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel);
        DemandeAbonnement::whereKey($enAttente->id)->update(['created_at' => now()->subDays(3)]);
        $this->actingAs($this->exploitant);

        // À traiter par défaut : seule la demande en attente, et son ancienneté.
        Livewire::test(Demandes::class)
            ->assertSee('1 demande')
            ->assertSee('Approuver')
            ->assertSee('Depuis 3 jours')
            ->assertViewHas('demandes', fn ($p) => $p->pluck('id')->all() === [$enAttente->id]);

        Livewire::test(Demandes::class)->set('filtre', 'approuvee')
            ->assertViewHas('demandes', fn ($p) => $p->pluck('id')->all() === [$approuvee->id]);

        // Recherche par boutique, par numéro (chiffres seuls) ou par nom.
        foreach (['pressing', '76 00 82 01', 'awa'] as $terme) {
            Livewire::test(Demandes::class)->set('filtre', 'toutes')->set('recherche', $terme)
                ->assertViewHas('demandes', fn ($p) => $p->count() === 2);
        }
        Livewire::test(Demandes::class)->set('filtre', 'toutes')->set('recherche', 'Boutique inconnue')
            ->assertViewHas('demandes', fn ($p) => $p->count() === 0)
            ->assertSee('Aucune demande ne correspond');
    }

    public function test_les_plans_se_reglent_depuis_la_console(): void
    {
        $basic = Plan::parCode('basic');
        $essai = Plan::parCode('essai');
        $this->actingAs($this->exploitant);

        Livewire::test(Plans::class)
            ->set("tarifs.{$basic->id}.monthly.montant", '5000')
            ->set("plans.{$essai->id}.jours_essai", '14')
            ->set("plans.{$basic->id}.max_membres", '')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $basic = Plan::parCode('basic');
        $this->assertSame(5000, $basic->tarif(CycleFacturation::Mensuel)->montant);
        $this->assertNull($basic->max_membres);
        $this->assertSame(14, Plan::parCode('essai')->jours_essai);
        $this->getJson('/api/plans')->assertJsonPath('data.1.tarifs.0.montant', 5000);
    }

    public function test_la_console_coche_les_fonctions_d_un_plan(): void
    {
        $basic = Plan::parCode('basic');
        $pro = Plan::parCode('pro');
        $this->actingAs($this->exploitant);

        Livewire::test(Plans::class)
            ->assertSet("plans.{$pro->id}.fonctionnalites.seances_caisse", true)
            ->set("plans.{$basic->id}.fonctionnalites.seances_caisse", true)
            ->set("plans.{$pro->id}.fonctionnalites.seances_caisse", false)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertTrue(Plan::parCode('basic')->inclut(Plan::SEANCES_CAISSE));
        $this->assertFalse(Plan::parCode('pro')->inclut(Plan::SEANCES_CAISSE));
        $this->assertTrue(Plan::parCode('essai')->inclut(Plan::SEANCES_CAISSE));
    }

    public function test_l_application_lit_et_enregistre_les_plans_comme_la_console_web(): void
    {
        $basic = Plan::parCode('basic');
        $essai = Plan::parCode('essai');
        $jeton = $this->exploitant->createToken('app')->plainTextToken;

        $plans = $this->withToken($jeton)->getJson('/api/plateforme/plans')->assertOk()->json('plans');
        $this->assertSame(['essai', 'basic', 'pro'], array_column($plans, 'code'));
        $this->assertSame([], $plans[0]['tarifs'], 'l’essai n’a pas de tarif');
        $this->assertCount(4, $plans[1]['tarifs']);

        $plans[1]['tarifs'][0]['montant'] = '5000';
        $plans[1]['max_membres'] = '';
        $plans[1]['fonctionnalites'] = [Plan::SEANCES_CAISSE];
        $plans[0]['jours_essai'] = '14';
        // L'essai reste proposé, même si l'on envoie le contraire.
        $plans[0]['est_actif'] = false;

        $this->withToken($jeton)->putJson('/api/plateforme/plans', ['plans' => $plans])->assertOk()
            ->assertJsonPath('plans.1.tarifs.0.montant', '5000');

        $basic = Plan::parCode('basic');
        $this->assertSame(5000, $basic->tarif(CycleFacturation::Mensuel)->montant);
        $this->assertNull($basic->max_membres);
        $this->assertTrue($basic->inclut(Plan::SEANCES_CAISSE));
        $this->assertSame(14, Plan::parCode('essai')->jours_essai);
        $this->assertTrue((bool) Plan::parCode('essai')->est_actif);
    }

    public function test_les_plans_de_l_api_refusent_un_montant_negatif_et_un_non_exploitant(): void
    {
        $jeton = $this->exploitant->createToken('app')->plainTextToken;
        $plans = $this->withToken($jeton)->getJson('/api/plateforme/plans')->json('plans');
        $plans[1]['tarifs'][0]['montant'] = '-10';
        $this->withToken($jeton)->putJson('/api/plateforme/plans', ['plans' => $plans])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($this->awa->createToken('t')->plainTextToken)->getJson('/api/plateforme/plans')->assertForbidden();
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
}
