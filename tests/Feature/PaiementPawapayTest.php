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
 * Abonnement payé via pawaPay au Sénégal, au Burkina Faso et au Bénin :
 * commission ajoutée au prix, dépôt relu chez pawaPay avant toute activation.
 */
class PaiementPawapayTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    /** Réponse de pawaPay à la relecture du dépôt. */
    private array $depot = ['status' => 'NOT_FOUND'];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['pawapay.token' => 'jeton-test', 'pawapay.environnement' => 'sandbox', 'pawapay.frais_pourcentage' => 3.0, 'pawapay.url_retour' => null]);
        // FedaPay actif aussi : pawaPay doit passer devant là où il opère.
        config(['fedapay.secret_key' => 'sk_sandbox_test', 'fedapay.webhook_secret' => 'wh_sandbox_test', 'fedapay.environnement' => 'sandbox']);

        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Awa', 'pays' => 'SN', 'telephone' => '771234567',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);

        Http::fake([
            'api.sandbox.pawapay.io/v2/paymentpage' => Http::response(['redirectUrl' => 'https://paywith.pawapay.io/?token=abc']),
            'api.sandbox.pawapay.io/v2/deposits/*' => fn () => Http::response($this->depot),
        ]);
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($this->awa->createToken('t')->plainTextToken);
    }

    private function payer(string $moyen = 'free_sen'): DemandeAbonnement
    {
        $reponse = $this->api()->postJson('/api/abonnement/paiement-mobile', ['plan' => 'pro', 'cycle' => 'monthly', 'moyen' => $moyen])
            ->assertCreated()->assertJsonPath('redirect_url', 'https://paywith.pawapay.io/?token=abc');

        return DemandeAbonnement::findOrFail($reponse->json('data.id'));
    }

    private function complete(DemandeAbonnement $demande, ?string $montant = null, string $statut = 'COMPLETED'): void
    {
        $this->depot = ['status' => 'FOUND', 'data' => [
            'depositId' => $demande->pawapay_deposit_id, 'status' => $statut,
            'amount' => $montant ?? ($demande->montant + $demande->frais_mobile).'.00', 'currency' => 'XOF',
        ]];
    }

    public function test_au_senegal_les_operateurs_pawapay_et_la_page_de_paiement(): void
    {
        $this->api()->getJson('/api/abonnement')
            ->assertJsonPath('data.paiement_mobile.titre', 'Payer par Mobile Money')
            ->assertJsonCount(2, 'data.paiement_mobile.moyens')
            ->assertJsonPath('data.paiement_mobile.moyens.1.code', 'free_sen')
            ->assertJsonPath('data.paiement_mobile.moyens.1.frais_pourcentage', 3);

        $demande = $this->payer();

        $this->assertSame(PaiementJeko::fraisAuTaux($demande->montant, 3.0), $demande->frais_mobile);
        $this->assertSame('pawapay_free_sen', $demande->moyen);
        $this->assertNotNull($demande->pawapay_deposit_id);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.sandbox.pawapay.io/v2/paymentpage'
            && $r['depositId'] === $demande->pawapay_deposit_id
            && $r['amountDetails'] === ['amount' => (string) ($demande->montant + $demande->frais_mobile), 'currency' => 'XOF']
            && $r['country'] === 'SEN'
            && str_contains($r['returnUrl'], 'reference=NGONI-ABO-'.$demande->id)
            && $r->hasHeader('Authorization', 'Bearer jeton-test'));
    }

    public function test_l_appel_de_pawapay_active_apres_relecture(): void
    {
        $demande = $this->payer();
        $this->complete($demande);

        $this->postJson('/api/webhooks/pawapay', ['depositId' => $demande->pawapay_deposit_id, 'status' => 'COMPLETED'])->assertOk();
        $this->postJson('/api/webhooks/pawapay', ['depositId' => $demande->pawapay_deposit_id, 'status' => 'COMPLETED'])->assertOk(); // rejoué

        $demande->refresh();
        $this->assertSame(StatutDemande::Approuvee, $demande->statut);
        $this->assertStringContainsString('Free Money via pawaPay', (string) $demande->note_decision);
        $this->assertSame('pro', $this->awa->abonnement()->first()->plan);
    }

    public function test_un_appel_invente_ou_un_mauvais_montant_n_activent_rien(): void
    {
        $demande = $this->payer();

        // pawaPay ne connaît pas ce dépôt : l'appel ne prouve rien.
        $this->postJson('/api/webhooks/pawapay', ['depositId' => $demande->pawapay_deposit_id, 'status' => 'COMPLETED'])->assertOk();
        $this->assertSame(StatutDemande::EnAttente, $demande->fresh()->statut);

        $this->complete($demande, '100.00');
        $this->postJson('/api/webhooks/pawapay', ['depositId' => $demande->pawapay_deposit_id])->assertOk();
        $this->assertSame(StatutDemande::EnAttente, $demande->fresh()->statut);

        $this->postJson('/api/webhooks/pawapay', ['depositId' => 'pas-un-uuid'])->assertOk();
        $this->assertSame('essai', $this->awa->abonnement()->first()->plan);
    }

    public function test_refuse_chez_pawapay_la_demande_s_annule(): void
    {
        $demande = $this->payer();
        $this->complete($demande, statut: 'FAILED');

        $this->api()->getJson("/api/abonnement/paiement-mobile/{$demande->id}")->assertOk()->assertJsonPath('data.statut', 'annulee');
    }

    public function test_page_fermee_sans_payer_annulee_apres_30_minutes(): void
    {
        $demande = $this->payer();
        $this->travel(35)->minutes();

        $this->artisan('ecaisse:verifier-paiements-mobile')->assertSuccessful();

        $this->assertSame(StatutDemande::Annulee, $demande->fresh()->statut);
    }

    public function test_hors_de_ses_pays_ou_sans_jeton_fedapay_reprend(): void
    {
        $this->boutique->update(['pays' => 'NE']);
        $this->api()->getJson('/api/abonnement')->assertJsonPath('data.paiement_mobile.moyens.0.code', 'airtel_ne');

        $this->boutique->update(['pays' => 'SN']);
        config(['pawapay.token' => null, 'fedapay.secret_key' => null]);
        $this->api()->getJson('/api/abonnement')->assertJsonPath('data.paiement_mobile', null);
    }
}
