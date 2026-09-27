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
            ->assertOk()->assertJsonPath('resultat.notifies', 2);
        $this->assertSame(1, NotificationApp::where('user_id', $this->awa->id)->where('titre', 'Maintenance')->count());
    }
}
