<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StatutDemande;
use App\Mail\DemandeAbonnementMail;
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
 * Abonnement payé par Mobile Money via Jèko : l'abonnement s'active seul,
 * et seulement quand Jèko confirme le paiement.
 */
class PaiementJekoTest extends TestCase
{
    use RefreshDatabase;

    private User $kone;

    private Boutique $boutique;

    /** Statut que le faux Jèko renvoie à la relecture d'un paiement. */
    private string $statutJeko = 'pending';

    private int $creees = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'jeko.api_key' => 'cle', 'jeko.api_key_id' => 'id-cle',
            'jeko.store_id' => 'magasin-1', 'jeko.webhook_secret' => 'secret-webhook',
        ]);

        ['user' => $this->kone, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Koné', 'pays' => 'CI', 'telephone' => '0701020304',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Koné',
        ]);

        Http::fake([
            // Un identifiant par demande, comme chez Jèko : pr-1, pr-2…
            'api.jeko.africa/partner_api/payment_requests' => fn (Request $r) => Http::response([
                'id' => 'pr-'.(++$this->creees), 'status' => 'pending', 'reference' => $r['reference'],
                'redirectUrl' => 'https://pay.jeko.africa/pay_request/pr/pr-'.$this->creees,
            ], 201),
            'api.jeko.africa/partner_api/payment_requests/*' => fn () => Http::response([
                'id' => 'pr-1', 'status' => $this->statutJeko, 'paymentMethod' => 'wave',
                'transaction' => $this->statutJeko === 'success' ? ['id' => 'txn-9', 'status' => 'success'] : null,
            ]),
        ]);
    }

    private function api(?User $user = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken(($user ?? $this->kone)->createToken('t')->plainTextToken);
    }

    private function payer(): DemandeAbonnement
    {
        $reponse = $this->api()->postJson('/api/abonnement/paiement-mobile', ['plan' => 'pro', 'cycle' => 'monthly', 'moyen' => 'wave'])
            ->assertCreated()
            ->assertJsonPath('redirect_url', 'https://pay.jeko.africa/pay_request/pr/pr-'.($this->creees));

        return DemandeAbonnement::findOrFail($reponse->json('data.id'));
    }

    private function webhook(array $corps, ?string $signature = null)
    {
        $brut = json_encode($corps);

        return $this->call('POST', '/api/webhooks/jeko', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_JEKO_EVENT' => 'TRANSACTION_COMPLETED',
            'HTTP_JEKO_SIGNATURE' => $signature ?? hash_hmac('sha256', $brut, 'secret-webhook'),
        ], $brut);
    }

    public function test_propose_aux_boutiques_ivoiriennes_et_cree_le_paiement_au_bon_montant(): void
    {
        $this->api()->getJson('/api/abonnement')->assertJsonPath('data.paiement_mobile.moyens.0.code', 'wave');

        $demande = $this->payer();

        $this->assertSame(StatutDemande::EnAttente, $demande->statut);
        $this->assertSame('jeko_wave', $demande->moyen);
        $this->assertSame('pr-1', $demande->jeko_paiement_id);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            // Prix + 1,5 % de frais : le prix entier reste une fois les frais prélevés.
            && $r['amountCents'] === ($demande->montant + $demande->frais_mobile) * 100
            && $r['storeId'] === 'magasin-1'
            && $r['reference'] === 'NGONI-ABO-'.$demande->id
            && $r->hasHeader('X-API-KEY', 'cle'));
        Mail::assertNotSent(DemandeAbonnementMail::class);
    }

    public function test_les_frais_mobile_money_sont_ajoutes_au_prix(): void
    {
        $this->assertSame(61, PaiementJeko::frais(4000));
        $this->assertSame(1523, PaiementJeko::frais(100000));

        $demande = $this->payer();
        $this->assertSame(PaiementJeko::frais($demande->montant), $demande->frais_mobile);
        $this->api()->getJson('/api/abonnement')->assertJsonPath('data.paiement_mobile.frais_pourcentage', 1.5);
    }

    public function test_un_paiement_reste_en_attente_s_active_a_la_verification_planifiee(): void
    {
        $demande = $this->payer();
        $this->statutJeko = 'success';

        $this->artisan('ecaisse:verifier-paiements-mobile')->assertSuccessful();

        $this->assertSame(StatutDemande::Approuvee, $demande->fresh()->statut);
    }

    public function test_le_webhook_signe_active_l_abonnement_une_seule_fois(): void
    {
        $demande = $this->payer();
        $this->statutJeko = 'success';
        $corps = ['id' => 'txn-9', 'status' => 'success', 'transactionType' => 'payment',
            'transactionDetails' => ['id' => 'pr-1', 'reference' => 'NGONI-ABO-'.$demande->id]];

        $this->webhook($corps)->assertOk();
        $this->webhook($corps)->assertOk(); // rejoué par Jèko

        $demande->refresh();
        $this->assertSame(StatutDemande::Approuvee, $demande->statut);
        $this->assertNull($demande->decide_par);
        $this->assertSame('txn-9', $demande->jeko_transaction_id);
        $abonnement = $this->kone->abonnement()->first();
        $this->assertSame('pro', $abonnement->plan);
        $this->assertSame(now()->startOfDay()->addMonth()->toDateString(), $abonnement->fin->toDateString());
    }

    public function test_une_signature_falsifiee_n_active_rien(): void
    {
        $demande = $this->payer();
        $this->statutJeko = 'success';

        $this->webhook(['transactionType' => 'payment', 'transactionDetails' => ['id' => 'pr-1']], 'faux')->assertStatus(401);

        $this->assertSame(StatutDemande::EnAttente, $demande->fresh()->statut);
        $this->assertSame('essai', $this->kone->abonnement()->first()->plan);
    }

    public function test_sans_paiement_confirme_chez_jeko_rien_ne_s_active(): void
    {
        $demande = $this->payer();

        // Webhook authentique mais paiement encore en cours chez Jèko : on attend.
        $this->webhook(['transactionType' => 'payment', 'transactionDetails' => ['id' => 'pr-1']])->assertOk();
        $this->assertSame(StatutDemande::EnAttente, $demande->fresh()->statut);

        // Refusé : la demande est annulée, le commerçant peut réessayer.
        $this->statutJeko = 'error';
        $this->api()->getJson("/api/abonnement/paiement-mobile/{$demande->id}")->assertOk()->assertJsonPath('data.statut', 'annulee');
        $this->assertSame('essai', $this->kone->abonnement()->first()->plan);
    }

    public function test_au_retour_le_statut_relu_active_l_abonnement(): void
    {
        $demande = $this->payer();
        $this->statutJeko = 'success';

        $this->get('/paiement-abonnement?reference=NGONI-ABO-'.$demande->id.'&issue=succes')->assertOk()->assertSee('Paiement reçu');

        $this->assertSame(StatutDemande::Approuvee, $demande->fresh()->statut);
    }

    public function test_sans_paiement_apres_30_minutes_la_demande_s_annule_avec_son_motif(): void
    {
        $demande = $this->payer();

        $this->travel(20)->minutes();
        $this->artisan('ecaisse:verifier-paiements-mobile')->assertSuccessful();
        $this->assertSame(StatutDemande::EnAttente, $demande->fresh()->statut, 'encore dans le délai');

        $this->travel(15)->minutes();
        $this->artisan('ecaisse:verifier-paiements-mobile')->assertSuccessful();

        $demande->refresh();
        $this->assertSame(StatutDemande::Annulee, $demande->statut);
        $this->assertStringContainsString('non reçu dans les 30 minutes', $demande->note_decision);
        $this->assertDatabaseHas('notifications_app', ['user_id' => $this->kone->id, 'titre' => 'Paiement de l’abonnement non reçu']);
    }

    public function test_une_tentative_abandonnee_ne_bloque_pas_la_suivante(): void
    {
        $premiere = $this->payer();

        $this->payer();

        $this->assertSame(StatutDemande::Annulee, $premiere->fresh()->statut);
    }

    public function test_hors_cote_d_ivoire_le_paiement_mobile_n_est_pas_propose(): void
    {
        $this->boutique->update(['pays' => 'ML']);

        $this->api()->getJson('/api/abonnement')->assertJsonPath('data.paiement_mobile', null);
        $this->api()->postJson('/api/abonnement/paiement-mobile', ['plan' => 'pro', 'moyen' => 'wave'])->assertUnprocessable();
        Http::assertNothingSent();
    }
}
