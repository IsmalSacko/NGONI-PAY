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
}
