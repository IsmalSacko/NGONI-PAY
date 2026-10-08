<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CycleFacturation;
use App\Models\Boutique;
use App\Models\NotificationApp;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ConsoleMobileTest extends TestCase
{
    use RefreshDatabase;

    private User $exploitant;

    private User $awa;

    private Boutique $boutiqueAwa;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // L'exploitant tient aussi sa boutique, comme en production.
        ['user' => $this->exploitant] = $this->inscrire('Pharmacie Les Castors', '73136789', 'Ismaila');
        $this->exploitant->forceFill(['est_admin_plateforme' => true])->save();
        $this->jeton = $this->exploitant->createToken('app')->plainTextToken;

        ['user' => $this->awa, 'boutique' => $this->boutiqueAwa] = $this->inscrire('Pressing Awa', '76008201', 'Awa');
    }

    private function inscrire(string $nom, string $tel, string $qui): array
    {
        return app(BoutiqueRegistrationService::class)->register([
            'nom' => $nom, 'pays' => 'ML', 'telephone' => $tel, 'email' => null, 'password' => 'password123', 'nom_utilisateur' => $qui,
        ]);
    }

    private function console(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->jeton);
    }

    public function test_la_console_est_reservee_a_l_exploitant(): void
    {
        $jetonAwa = $this->awa->createToken('app')->plainTextToken;
        $this->withToken($jetonAwa)->getJson('/api/plateforme/tableau')->assertForbidden();
        $this->withToken($jetonAwa)->getJson('/api/plateforme/demandes')->assertForbidden();

        // L'exploitant voit toute la plateforme, pas seulement sa boutique.
        $this->console()->getJson('/api/plateforme/tableau')->assertOk()->assertJsonPath('boutiques', 2);
        $this->console()->getJson('/api/plateforme/comptes?recherche=pressing')->assertOk()
            ->assertJsonPath('data.0.nom', 'Awa')->assertJsonPath('data.0.boutiques.0', 'Pressing Awa');
        $this->console()->getJson('/api/moi')->assertOk()->assertJsonPath('user.est_admin_plateforme', true);
    }

    public function test_on_trouve_un_compte_par_le_nom_de_sa_boutique_partout(): void
    {
        // Le nom de la boutique n'est pas celui de la personne : c'est pourtant
        // lui que l'exploitant tape.
        ['user' => $fassely] = $this->inscrire('Sacko multi Services', '74174753', 'Sacko Fassely');

        $this->console()->getJson('/api/plateforme/annonces/comptes?q=multi services')->assertOk()
            ->assertJsonPath('data.0.id', $fassely->id);
        $this->console()->getJson('/api/plateforme/utilisateurs?recherche=Sacko multi Services')->assertOk()
            ->assertJsonFragment(['id' => $fassely->id]);
        $this->assertSame([$fassely->id], app(\App\Services\GestionAnnonces::class)->rechercherComptes('MULTI serv')->pluck('id')->all());
    }

    public function test_l_exploitant_est_prevenu_des_inscriptions_et_des_demandes(): void
    {
        $alertes = fn () => NotificationApp::where('user_id', $this->exploitant->id)->where('type', 'console');

        $this->assertSame(1, $alertes()->where('lien', '/console/comptes')->count(), 'inscription d’Awa');
        $this->assertStringContainsString('Awa · Pressing Awa', $alertes()->where('titre', 'Nouvelle inscription')->value('message'));

        $demande = app(AbonnementService::class)->soumettre($this->boutiqueAwa, $this->awa, 'basic', CycleFacturation::Mensuel);
        $this->assertSame(1, $alertes()->where('titre', 'Demande d’abonnement')->where('lien', '/console/demandes')->count());
        $this->assertStringContainsString('Pressing Awa demande', $alertes()->where('titre', 'Demande d’abonnement')->value('message'));

        app(AbonnementService::class)->annuler($demande);
        $this->assertSame(1, $alertes()->where('titre', 'Demande annulée')->count());

        // Les commerçants ne reçoivent pas ces alertes.
        $this->assertSame(0, NotificationApp::where('user_id', $this->awa->id)->where('type', 'console')->count());
    }

    public function test_approuver_et_refuser_une_demande_depuis_l_application(): void
    {
        $service = app(AbonnementService::class);
        $demande = $service->soumettre($this->boutiqueAwa, $this->awa, 'basic', CycleFacturation::Mensuel);

        $this->console()->getJson('/api/plateforme/demandes')->assertOk()
            ->assertJsonPath('data.0.id', $demande->id)->assertJsonPath('data.0.boutique', 'Pressing Awa')
            ->assertJsonPath('data.0.whatsapp', 'https://wa.me/22376008201');
        $this->console()->postJson("/api/plateforme/demandes/{$demande->id}/approuver")->assertOk()->assertJsonPath('abonnement.plan', 'basic');
        $this->assertSame('basic', $this->awa->abonnement()->first()->plan);

        $autre = $service->soumettre($this->boutiqueAwa, $this->awa, 'pro', CycleFacturation::Mensuel);
        $this->console()->postJson("/api/plateforme/demandes/{$autre->id}/refuser")->assertUnprocessable()->assertJsonValidationErrors('motif');
        $this->console()->postJson("/api/plateforme/demandes/{$autre->id}/refuser", ['motif' => 'Paiement non reçu'])->assertOk();
        $this->assertSame('refusee', $autre->fresh()->statut->value);
    }

    public function test_accorder_puis_revoquer_un_abonnement(): void
    {
        $fin = now()->addMonths(3)->toDateString();
        $this->console()->postJson("/api/plateforme/comptes/{$this->awa->id}/accorder", ['plan' => 'pro', 'fin' => $fin])
            ->assertOk()->assertJsonPath('abonnement.plan', 'pro')->assertJsonPath('abonnement.fin', $fin);

        $this->console()->postJson("/api/plateforme/comptes/{$this->awa->id}/revoquer")->assertOk();
        $this->assertFalse($this->awa->abonnement()->first()->estEnCours());
    }

    public function test_desactiver_un_compte_et_donner_un_mot_de_passe_provisoire(): void
    {
        $this->console()->postJson("/api/plateforme/utilisateurs/{$this->exploitant->id}/basculer")->assertUnprocessable();

        $this->awa->createToken('téléphone');
        $this->console()->postJson("/api/plateforme/utilisateurs/{$this->awa->id}/basculer")->assertOk()->assertJsonPath('actif', false);
        $this->assertSame(0, $this->awa->tokens()->count(), 'déconnectée partout');
        $this->console()->postJson("/api/plateforme/utilisateurs/{$this->awa->id}/basculer")->assertOk()->assertJsonPath('actif', true);

        $r = $this->console()->postJson("/api/plateforme/utilisateurs/{$this->awa->id}/mot-de-passe")->assertOk();
        $this->assertSame(10, strlen($r->json('mot_de_passe')));
        $this->assertStringStartsWith('https://wa.me/22376008201?text=', $r->json('whatsapp'));
        $this->postJson('/api/connexion', ['telephone' => '76008201', 'password' => $r->json('mot_de_passe'), 'pays' => 'ML'])->assertOk();
    }

    public function test_envoyer_une_annonce_depuis_l_application(): void
    {
        $this->console()->postJson('/api/plateforme/annonces', ['titre' => 'Maintenance', 'message' => 'Ce soir à 22 h.', 'audience' => 'tous'])
            ->assertCreated()->assertJsonPath('lancee', true)->assertJsonPath('message', fn ($m) => str_contains($m, 'part vers 2 compte(s)'));
        $this->assertSame(1, NotificationApp::where('user_id', $this->awa->id)->where('titre', 'Maintenance')->count());
    }

    public function test_l_application_a_les_memes_annonces_que_le_web(): void
    {
        $liste = $this->console()->getJson('/api/plateforme/annonces')->assertOk();
        $this->assertSame(['mise_a_jour', 'message', 'campagne'], array_keys($liste->json('types')));
        $this->assertArrayHasKey('selection', $liste->json('audiences'));
        $this->assertSame(['hebdomadaire', 'mensuelle'], array_keys($liste->json('recurrences')));

        // Une mise à jour arrive préremplie ; une campagne se programme.
        $this->console()->getJson('/api/plateforme/annonces/modele/mise_a_jour')->assertOk()
            ->assertJsonPath('type', 'mise_a_jour')->assertJsonPath('quand', 'maintenant')
            ->assertJsonPath('titre', 'Nouvelle version de l’application');
        $this->console()->getJson('/api/plateforme/annonces/modele/campagne')->assertOk()->assertJsonPath('quand', 'programmer');
        // Message libre : le lien mène par défaut au back-office des boutiques.
        $this->console()->getJson('/api/plateforme/annonces/modele/message')->assertOk()->assertJsonPath('lien', url('/app'));
        $this->console()->getJson('/api/plateforme/annonces/modele/mise_a_jour')->assertOk()->assertJsonPath('lien', config('mobile.store_url'));
    }

    public function test_une_campagne_programmee_ne_part_pas_et_peut_etre_arretee(): void
    {
        $this->console()->postJson('/api/plateforme/annonces', [
            'type' => 'campagne', 'titre' => 'Promo Pro', 'message' => 'Un mois offert.', 'audience' => 'tous',
            'quand' => 'programmer', 'programmee_le' => now()->addDay()->toIso8601String(), 'recurrence' => 'mensuelle',
        ])->assertCreated()->assertJsonPath('lancee', false)->assertJsonPath('message', fn ($m) => str_contains($m, 'programmée'));
        $this->assertSame(0, NotificationApp::where('titre', 'Promo Pro')->count(), 'rien envoyé avant la date');

        $annonce = $this->console()->getJson('/api/plateforme/annonces')->json('data.0');
        $this->assertSame(['campagne', 'programmee', 'mensuelle'], [$annonce['type'], $annonce['statut'], $annonce['recurrence']]);

        $this->console()->postJson("/api/plateforme/annonces/{$annonce['id']}/arreter")->assertOk();
        $this->assertSame('brouillon', $this->console()->getJson('/api/plateforme/annonces')->json('data.0.statut'));

        $this->console()->postJson("/api/plateforme/annonces/{$annonce['id']}/envoyer")->assertOk();
        $this->assertSame(2, NotificationApp::where('titre', 'Promo Pro')->count(), 'envoyée à la demande');
    }

    public function test_une_annonce_a_des_comptes_choisis(): void
    {
        $trouves = $this->console()->getJson('/api/plateforme/annonces/comptes?q='.urlencode($this->awa->name))->assertOk()->json('data');
        $this->assertSame([$this->awa->id], array_column($trouves, 'id'));
        $this->console()->getJson('/api/plateforme/annonces/comptes?q=a')->assertOk()->assertJsonCount(0, 'data');

        $this->console()->postJson('/api/plateforme/annonces/apercu', ['audience' => 'selection', 'cibles' => [$this->awa->id]])
            ->assertOk()->assertJsonPath('destinataires', 1);
        $this->console()->postJson('/api/plateforme/annonces', ['titre' => 'Rien que pour vous', 'message' => '…', 'audience' => 'selection'])
            ->assertUnprocessable()->assertJsonValidationErrors('cibles');

        $this->console()->postJson('/api/plateforme/annonces', ['titre' => 'Rien que pour vous', 'message' => '…', 'audience' => 'selection', 'cibles' => [$this->awa->id]])
            ->assertCreated()->assertJsonPath('message', fn ($m) => str_contains($m, 'part vers 1 compte(s)'));
        $this->assertSame([$this->awa->id], NotificationApp::where('titre', 'Rien que pour vous')->pluck('user_id')->all());
    }
}
