<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StatutDemande;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Services\PaiementJeko;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Abonnement payé en ligne via FedaPay, hors Côte d'Ivoire : Mobile Money du
 * pays (Airtel au Niger…) et carte partout, commission ajoutée au prix.
 */
class PaiementFedapayTest extends TestCase
{
    use RefreshDatabase;

    private User $moussa;

    private Boutique $boutique;

    private string $statutFedapay = 'pending';

    private ?int $montantFedapay = null;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['fedapay.secret_key' => 'sk_sandbox_test', 'fedapay.webhook_secret' => 'wh_sandbox_test', 'fedapay.environnement' => 'sandbox']);

        ['user' => $this->moussa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Moussa', 'pays' => 'NE', 'telephone' => '90494929',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Moussa',
        ]);

        Http::fake([
            'sandbox-api.fedapay.com/v1/transactions' => fn (Request $r) => Http::response(['v1/transaction' => ['id' => 777, 'status' => 'pending', 'amount' => $r['amount']]], 201),
            'sandbox-api.fedapay.com/v1/transactions/777/token' => Http::response(['token' => 'tok', 'url' => 'https://process.fedapay.com/tok']),
            'sandbox-api.fedapay.com/v1/transactions/777' => fn () => Http::response(['v1/transaction' => [
                'id' => 777, 'status' => $this->statutFedapay, 'amount' => $this->montantFedapay ?? DemandeAbonnement::first()?->montant + DemandeAbonnement::first()?->frais_mobile,
            ]]),
        ]);
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($this->moussa->createToken('t')->plainTextToken);
    }

    private function payer(string $moyen = 'airtel_ne'): DemandeAbonnement
    {
        $reponse = $this->api()->postJson('/api/abonnement/paiement-mobile', ['plan' => 'pro', 'cycle' => 'monthly', 'moyen' => $moyen])
            ->assertCreated()->assertJsonPath('redirect_url', 'https://process.fedapay.com/tok');

        return DemandeAbonnement::findOrFail($reponse->json('data.id'));
    }

    private function webhook(array $evenement, ?string $signature = null)
    {
        $brut = json_encode($evenement);
        $t = time();

        return $this->call('POST', '/api/webhooks/fedapay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FEDAPAY_SIGNATURE' => $signature ?? 't='.$t.',s='.hash_hmac('sha256', $t.'.'.$brut, 'wh_sandbox_test'),
        ], $brut);
    }

    public function test_au_niger_airtel_et_la_carte_sont_proposes_avec_leur_commission(): void
    {
        $this->api()->getJson('/api/abonnement')
            ->assertJsonPath('data.paiement_mobile.titre', 'Payer par Mobile Money')
            ->assertJsonPath('data.paiement_mobile.moyens.0.code', 'airtel_ne')
            ->assertJsonPath('data.paiement_mobile.moyens.0.frais_pourcentage', 4)
            ->assertJsonPath('data.paiement_mobile.moyens.1.code', 'carte');

        $demande = $this->payer();

        // 4 % répercutés : le prix entier reste une fois la commission prélevée.
        $this->assertSame(PaiementJeko::fraisAuTaux($demande->montant, 4.0), $demande->frais_mobile);
        $this->assertSame('fedapay_airtel_ne', $demande->moyen);
        $this->assertSame('777', $demande->fedapay_transaction_id);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://sandbox-api.fedapay.com/v1/transactions'
            && $r['amount'] === $demande->montant + $demande->frais_mobile
            && $r['mode'] === 'airtel_ne'
            && $r['currency'] === ['iso' => 'XOF']
            && $r->hasHeader('Authorization', 'Bearer sk_sandbox_test'));
    }

    public function test_au_mali_orange_money_et_la_carte_sont_proposes(): void
    {
        $this->boutique->update(['pays' => 'ML']);

        $this->api()->getJson('/api/abonnement')
            ->assertJsonPath('data.paiement_mobile.titre', 'Payer par Mobile Money')
            ->assertJsonPath('data.paiement_mobile.moyens.0.code', 'orange_ml')
            ->assertJsonPath('data.paiement_mobile.moyens.1.code', 'carte');
        $this->api()->postJson('/api/abonnement/paiement-mobile', ['plan' => 'pro', 'moyen' => 'airtel_ne'])->assertUnprocessable();

        // Orange Mali n'a pas de mode API : la page FedaPay propose le choix.
        $demande = $this->payer('orange_ml');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://sandbox-api.fedapay.com/v1/transactions' && ! isset($r['mode']));
        $this->assertSame(PaiementJeko::fraisAuTaux($demande->montant, 4.0), $demande->frais_mobile);
    }

    public function test_la_ou_seule_la_carte_existe_le_titre_le_dit(): void
    {
        $this->boutique->update(['pays' => 'CM']);

        $this->api()->getJson('/api/abonnement')
            ->assertJsonPath('data.paiement_mobile.titre', 'Payer par carte bancaire')
            ->assertJsonCount(1, 'data.paiement_mobile.moyens')
            ->assertJsonPath('data.paiement_mobile.moyens.0.frais_pourcentage', 3.6);
    }

    public function test_le_webhook_signe_active_l_abonnement_apres_relecture_chez_fedapay(): void
    {
        $demande = $this->payer();
        $this->statutFedapay = 'approved';

        $this->webhook(['name' => 'transaction.approved', 'entity' => ['id' => 777]])->assertOk();
        $this->webhook(['name' => 'transaction.approved', 'entity' => ['id' => 777]])->assertOk(); // rejoué

        $demande->refresh();
        $this->assertSame(StatutDemande::Approuvee, $demande->statut);
        $this->assertStringContainsString('Airtel Money via FedaPay', (string) $demande->note_decision);
        $this->assertSame('pro', $this->moussa->abonnement()->first()->plan);
    }

    public function test_signature_falsifiee_ou_montant_different_n_activent_rien(): void
    {
        $demande = $this->payer();
        $this->statutFedapay = 'approved';

        $this->webhook(['name' => 'transaction.approved', 'entity' => ['id' => 777]], 't='.time().',s=faux')->assertStatus(401);
        $this->assertSame(StatutDemande::EnAttente, $demande->fresh()->statut);

        // Payé, mais pas le montant fixé par le serveur : rien ne s'active.
        $this->montantFedapay = 100;
        $this->webhook(['name' => 'transaction.approved', 'entity' => ['id' => 777]])->assertOk();
        $this->assertSame(StatutDemande::EnAttente, $demande->fresh()->statut);
        $this->assertSame('essai', $this->moussa->abonnement()->first()->plan);
    }

    public function test_refuse_chez_fedapay_la_demande_s_annule(): void
    {
        $demande = $this->payer();
        $this->statutFedapay = 'declined';

        $this->api()->getJson("/api/abonnement/paiement-mobile/{$demande->id}")->assertOk()->assertJsonPath('data.statut', 'annulee');
    }

    public function test_sans_paiement_apres_30_minutes_la_demande_s_annule(): void
    {
        $demande = $this->payer();
        $this->travel(35)->minutes();

        $this->artisan('ecaisse:verifier-paiements-mobile')->assertSuccessful();

        $this->assertSame(StatutDemande::Annulee, $demande->fresh()->statut);
        $this->assertStringContainsString('non reçu dans les 30 minutes', (string) $demande->fresh()->note_decision);
    }

    public function test_sans_cles_fedapay_rien_n_est_propose(): void
    {
        config(['fedapay.secret_key' => null]);

        $this->api()->getJson('/api/abonnement')->assertJsonPath('data.paiement_mobile', null);
    }
}
