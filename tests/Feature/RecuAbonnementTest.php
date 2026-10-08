<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DemandeAbonnement;
use App\Models\NotificationApp;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Abonnement validé : un reçu numéroté, la période que le paiement couvre,
 * annoncé dans la notification, téléchargeable en PDF par sa boutique seule.
 */
class RecuAbonnementTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
    }

    private function api(?User $user = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken(($user ?? $this->awa)->createToken('t')->plainTextToken);
    }

    private function payer(string $plan = 'pro'): DemandeAbonnement
    {
        $id = $this->api()->postJson('/api/abonnement/demandes', ['plan' => $plan, 'cycle' => 'monthly', 'moyen' => 'orange_money'])
            ->assertAccepted()->json('data.id');
        $demande = DemandeAbonnement::findOrFail($id);
        app(AbonnementService::class)->approuver($demande, null);

        return $demande->fresh();
    }

    public function test_un_abonnement_valide_a_son_recu_et_la_notification_l_annonce(): void
    {
        $demande = $this->payer();

        $this->assertSame('NGC-'.now()->format('Y').'-'.str_pad((string) $demande->id, 5, '0', STR_PAD_LEFT), $demande->recu_numero);
        $this->assertSame(today()->toDateString(), $demande->periode_debut->toDateString());
        $this->assertSame($this->awa->abonnement()->first()->fin->toDateString(), $demande->periode_fin->toDateString());
        $this->assertStringContainsString("reçu n° {$demande->recu_numero}", NotificationApp::where('user_id', $this->awa->id)->latest('id')->value('message'));

        $this->api()->getJson('/api/abonnement/demandes')->assertJsonPath('data.0.recu_numero', $demande->recu_numero);
        $pdf = $this->api()->get("/api/abonnement/recus/{$demande->id}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_un_renouvellement_couvre_la_periode_qui_suit_l_echeance(): void
    {
        $premiere = $this->payer();
        $seconde = $this->payer();

        $this->assertSame($premiere->periode_fin->toDateString(), $seconde->periode_debut->toDateString());
        $this->assertTrue($seconde->periode_fin->gt($premiere->periode_fin));
    }

    public function test_pas_de_recu_pour_une_demande_en_attente_ni_pour_une_autre_boutique(): void
    {
        $payee = $this->payer('basic');
        // Une demande pas encore payée n'a pas de reçu.
        $id = $this->api()->postJson('/api/abonnement/demandes', ['plan' => 'pro', 'cycle' => 'monthly', 'moyen' => 'wave'])->assertAccepted()->json('data.id');
        $this->api()->get("/api/abonnement/recus/{$id}")->assertNotFound();

        ['user' => $autre] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Moussa', 'pays' => 'ML', 'telephone' => '76008202',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Moussa',
        ]);
        $this->api($autre)->get("/api/abonnement/recus/{$payee->id}")->assertNotFound();
    }
}
