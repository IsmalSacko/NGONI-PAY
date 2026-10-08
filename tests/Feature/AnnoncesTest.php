<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Plateforme\Annonces;
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

    public function test_une_mise_a_jour_notifie_tout_le_monde_sans_e_mail(): void
    {
        $this->actingAs($this->exploitant);

        Livewire::test(Annonces::class)
            ->call('nouvelle', 'mise_a_jour')
            ->assertSet('lien', config('mobile.store_url'))
            ->set('version', '3.0.0')
            ->call('enregistrer')
            ->assertHasNoErrors()
            ->assertSet('info', fn ($i) => str_contains($i, 'part vers 2 compte(s)'));

        $annonce = Annonce::sole();
        $this->assertSame(['envoyee', 2], [$annonce->statut, $annonce->nb_notifies]);

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

        // Balayée : supprimée, et seulement la sienne.
        $autre = \App\Models\NotificationApp::where('user_id', '!=', $this->moussa->id)->value('id');
        $this->withToken($jeton)->deleteJson("/api/notifications/{$autre}")->assertOk();
        $this->assertNotNull(\App\Models\NotificationApp::find($autre), 'celle d’un autre compte reste');
        $this->withToken($jeton)->deleteJson("/api/notifications/{$id}")->assertOk();
        $this->assertNull(\App\Models\NotificationApp::find($id));
    }

    public function test_l_exploitant_qui_tient_une_boutique_recoit_les_annonces(): void
    {
        // Sans boutique : écarté (il n'utilise que la console).
        $sansBoutique = app(\App\Services\DiffusionAnnonces::class)->destinataires(new \App\Models\Annonce(['audience' => 'tous']))->pluck('id');
        $this->assertNotContains($this->exploitant->id, $sansBoutique);

        // Avec une boutique : commerçant comme les autres.
        $this->exploitant->forceFill(['boutique_id' => \App\Models\Boutique::value('id')])->save();
        $avecBoutique = app(\App\Services\DiffusionAnnonces::class)->destinataires(new \App\Models\Annonce(['audience' => 'tous']))->pluck('id');
        $this->assertContains($this->exploitant->id, $avecBoutique);
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
