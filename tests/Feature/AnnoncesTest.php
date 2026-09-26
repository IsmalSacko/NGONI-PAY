<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Plateforme\Annonces;
use App\Mail\AnnonceMail;
use App\Models\Annonce;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Services\DiffusionAnnonces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class AnnoncesTest extends TestCase
{
    use RefreshDatabase;

    private User $exploitant;

    private User $awa;

    private User $moussa;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $inscrire = fn (string $nom, string $tel, ?string $email) => app(BoutiqueRegistrationService::class)->register([
            'nom' => "Boutique $nom", 'pays' => 'ML', 'telephone' => $tel,
            'email' => $email, 'password' => 'password123', 'nom_utilisateur' => $nom,
        ])['user'];

        $this->awa = $inscrire('Awa', '76008201', 'awa@example.com');
        $this->moussa = $inscrire('Moussa', '76008202', null);
        $this->moussa->abonnement()->update(['fin' => now()->subDay()->toDateString()]);
        $this->exploitant = User::factory()->create(['est_admin_plateforme' => true]);
    }

    public function test_une_mise_a_jour_notifie_tout_le_monde_et_ecrit_a_ceux_qui_ont_un_email(): void
    {
        $this->actingAs($this->exploitant);

        Livewire::test(Annonces::class)
            ->call('nouvelle', 'mise_a_jour')
            ->assertSet('lien', config('mobile.store_url'))
            ->set('version', '3.0.0')
            ->call('enregistrer')
            ->assertHasNoErrors()
            ->assertSet('info', fn ($i) => str_contains($i, '2 notification(s), 0 push, 1 e-mail(s)'));

        Mail::assertSent(AnnonceMail::class, fn (AnnonceMail $m) => $m->hasTo('awa@example.com'));
        Mail::assertSentCount(1);

        // L'application de Moussa voit la notification, non lue.
        $this->app['auth']->forgetGuards();
        $jeton = $this->moussa->createToken('t')->plainTextToken;
        $this->withToken($jeton)->getJson('/api/notifications')
            ->assertOk()->assertJsonPath('non_lues', 1)
            ->assertJsonPath('data.0.type', 'mise_a_jour')
            ->assertJsonPath('data.0.lien', config('mobile.store_url'));
        $id = $this->withToken($jeton)->getJson('/api/notifications')->json('data.0.id');
        $this->withToken($jeton)->postJson("/api/notifications/{$id}/lue")->assertOk();
        $this->withToken($jeton)->getJson('/api/notifications')->assertJsonPath('non_lues', 0);
    }

    public function test_cibler_les_expires_ou_des_comptes_choisis(): void
    {
        $diffusion = app(DiffusionAnnonces::class);

        $expires = Annonce::create(['type' => 'message', 'titre' => 'Relance', 'message' => 'Votre essai est fini', 'audience' => 'expires']);
        $this->assertSame([$this->moussa->id], $diffusion->destinataires($expires)->pluck('id')->all());

        $essais = Annonce::create(['type' => 'message', 'titre' => 'Astuce', 'message' => '…', 'audience' => 'essai']);
        $this->assertSame([$this->awa->id], $diffusion->destinataires($essais)->pluck('id')->all());

        $this->actingAs($this->exploitant);
        Livewire::test(Annonces::class)
            ->call('nouvelle', 'message')
            ->set('titre', 'Bonjour Moussa')->set('message', 'Un mot rien que pour vous')
            ->set('audience', 'selection')
            ->call('enregistrer')->assertHasErrors('cibles')
            ->call('basculerCible', $this->moussa->id)
            ->call('enregistrer')->assertHasNoErrors();

        $this->assertDatabaseHas('notifications_app', ['user_id' => $this->moussa->id, 'titre' => 'Bonjour Moussa']);
        $this->assertDatabaseMissing('notifications_app', ['user_id' => $this->awa->id, 'titre' => 'Bonjour Moussa']);
    }

    public function test_une_campagne_programmee_part_a_l_heure_et_se_repete(): void
    {
        $this->actingAs($this->exploitant);
        Livewire::test(Annonces::class)
            ->call('nouvelle', 'campagne')
            ->set('titre', 'Pensez au Pro')->set('message', '5 boutiques, équipe illimitée')
            ->set('programmee_le', now()->addHour()->format('Y-m-d\TH:i'))
            ->set('recurrence', 'hebdomadaire')
            ->call('enregistrer')->assertHasNoErrors();

        $this->artisan('ecaisse:diffuser-annonces')->assertSuccessful();
        $this->assertDatabaseCount('notifications_app', 0);

        $this->travel(2)->hours();
        $this->artisan('ecaisse:diffuser-annonces')->assertSuccessful();
        $this->assertDatabaseCount('notifications_app', 2);

        $annonce = Annonce::firstOrFail();
        $this->assertSame('programmee', $annonce->statut);
        $this->assertTrue($annonce->programmee_le->isAfter(now()->addDays(6)));
    }

    public function test_la_console_des_annonces_est_reservee_a_l_exploitant(): void
    {
        $this->actingAs($this->awa)->get('/plateforme/annonces')->assertForbidden();
        $this->actingAs($this->exploitant)->get('/plateforme/annonces')->assertOk()->assertSee('Annonces');
    }
}
