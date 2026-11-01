<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CycleFacturation;
use App\Enums\StatutDemande;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\JournalAbonnement;
use App\Models\Plan;
use App\Models\User;
use App\Models\Vente;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FabriqueApple;
use Tests\TestCase;

/**
 * Abonnement acheté dans l'application iPhone (achat intégré Apple) :
 * transaction signée vérifiée puis accordée jusqu'à la fin vendue par Apple,
 * renouvellements, fins et remboursements suivis par les notifications V2.
 * La chaîne « Apple » est fabriquée pour les tests (FabriqueApple).
 */
class AbonnementAppleTest extends TestCase
{
    use RefreshDatabase;

    private static ?FabriqueApple $apple = null;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['apple.racine' => self::apple()->racine, 'apple.environnements' => ['Sandbox', 'Production']]);
        // Le matin : la fin vendue par Apple tombe avant 16 h, le jour même.
        $this->travelTo(now()->addDay()->setTime(10, 0));

        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
    }

    private static function apple(): FabriqueApple
    {
        return self::$apple ??= new FabriqueApple;
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($this->awa->createToken('t')->plainTextToken);
    }

    /** @return array<string, mixed> une transaction d'abonnement telle que StoreKit 2 la signe */
    private function transaction(array $plus = []): array
    {
        return [
            'transactionId' => '2000000000000001',
            'originalTransactionId' => '2000000000000001',
            'bundleId' => 'com.ismaeldev.ngonipay',
            'productId' => 'ngonicaisse.pro.mensuel',
            'subscriptionGroupIdentifier' => '21000001',
            'purchaseDate' => now()->getTimestampMs(),
            'originalPurchaseDate' => now()->getTimestampMs(),
            'expiresDate' => now()->addMonth()->getTimestampMs(),
            'quantity' => 1,
            'type' => 'Auto-Renewable Subscription',
            'appAccountToken' => $this->awa->id,
            'inAppOwnershipType' => 'PURCHASED',
            'signedDate' => now()->getTimestampMs(),
            'environment' => 'Production',
            'transactionReason' => 'PURCHASE',
            'storefront' => 'MLI',
            'price' => 16990,
            'currency' => 'USD',
            ...$plus,
        ];
    }

    private function acheter(array $plus = []): TestResponse
    {
        return $this->api()->postJson('/api/abonnement/apple', ['signed_transaction' => self::apple()->jws($this->transaction($plus))]);
    }

    /** Une notification V2 de l'App Store portant cette transaction. */
    private function notifier(string $type, array $transaction, ?string $sousType = null, ?FabriqueApple $signataire = null): TestResponse
    {
        $signataire ??= self::apple();

        return $this->postJson('/api/apple/notifications', ['signedPayload' => $signataire->jws([
            'notificationType' => $type,
            'subtype' => $sousType,
            'notificationUUID' => '9ad56bd2-0bc6-42e0-af24-fd996d87a1e6',
            'data' => [
                'appAppleId' => 6822087487,
                'bundleId' => 'com.ismaeldev.ngonipay',
                'bundleVersion' => '42101',
                'environment' => $transaction['environment'] ?? 'Production',
                'signedTransactionInfo' => $signataire->jws($transaction),
                'status' => 1,
            ],
            'version' => '2.0',
            'signedDate' => now()->getTimestampMs(),
        ])]);
    }

    private function abonnement()
    {
        return $this->awa->abonnement()->first();
    }

    public function test_un_achat_verifie_accorde_la_formule_jusqu_a_la_fin_vendue_par_apple(): void
    {
        ['user' => $exploitant, 'boutique' => $caisse] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'NGONI CAISSE', 'pays' => 'ML', 'telephone' => '76999002',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Ismaël',
        ]);
        $exploitant->forceFill(['est_admin_plateforme' => true])->save();
        config(['ecaisse.boutique_ventes_abonnements' => $caisse->id]);
        $expire = now()->addMonth()->setTime(9, 30);

        // L'identifiant du compte, quelle que soit sa casse.
        $this->acheter(['expiresDate' => $expire->getTimestampMs(), 'appAccountToken' => strtoupper($this->awa->id)])
            ->assertOk()
            ->assertJsonPath('data.plan', 'pro')
            ->assertJsonPath('data.est_essai', false)
            ->assertJsonPath('data.est_en_cours', true)
            ->assertJsonPath('data.fin', $expire->toDateString())
            ->assertJsonPath('data.demande_en_attente', null);

        $this->assertSame('2000000000000001', $this->abonnement()->apple_transaction_originale);

        // Une demande payée, approuvée d'elle-même, au prix de la formule ; ce qu'Apple a facturé est noté.
        $demande = DemandeAbonnement::sole();
        $this->assertSame(StatutDemande::Approuvee, $demande->statut);
        $this->assertNull($demande->decide_par);
        $this->assertSame('apple_app_store', $demande->moyen);
        $this->assertSame('2000000000000001', $demande->referencePaiement());
        $this->assertSame(Plan::parCode('pro')->tarif(CycleFacturation::Mensuel)->montant, $demande->montant);
        $this->assertSame($expire->toDateString(), $demande->periode_fin->toDateString());
        $this->assertNotNull($demande->recu_numero);
        $this->assertStringContainsString('16,99 USD', $demande->note_decision);
        $this->assertSame('Paiement en ligne', $demande->decidePar());

        // Et la vente dans la boutique de l'exploitant.
        $vente = Vente::withoutGlobalScopes()->where('boutique_id', $caisse->id)->with('lignes')->sole();
        $this->assertSame((int) $demande->montant, (int) $vente->total);
        $this->assertSame('Plan Pro · 1 mois — App Store', $vente->lignes->sole()->nom_produit);
    }

    public function test_rejouer_la_meme_transaction_ne_cree_rien_de_plus(): void
    {
        $this->acheter()->assertOk();
        $fin = $this->abonnement()->fin->toDateString();

        $this->acheter()->assertOk()->assertJsonPath('data.fin', $fin);
        // La notification d'Apple pour le même achat, après l'application.
        $this->notifier('SUBSCRIBED', $this->transaction(), 'INITIAL_BUY')->assertOk();

        $this->assertSame(1, DemandeAbonnement::count());
        $this->assertSame($fin, $this->abonnement()->fin->toDateString());
    }

    public function test_un_achat_d_une_autre_application_d_un_produit_inconnu_ou_d_un_autre_compte_est_refuse(): void
    {
        ['user' => $autre] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Fatou', 'pays' => 'ML', 'telephone' => '76008202',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Fatou',
        ]);

        $this->acheter(['bundleId' => 'com.autre.application'])->assertUnprocessable()
            ->assertJsonPath('errors.signed_transaction.0', 'Cet achat a été fait dans une autre application.');
        $this->acheter(['productId' => 'ngonicaisse.or.mensuel'])->assertUnprocessable()
            ->assertJsonPath('errors.signed_transaction.0', 'Ce produit de l’App Store n’est pas une formule Ngoni Caisse.');
        $this->acheter(['appAccountToken' => $autre->id])->assertUnprocessable()
            ->assertJsonPath('errors.signed_transaction.0', 'Cet achat de l’App Store a été fait depuis un autre compte Ngoni Caisse : il ne peut pas servir à celui-ci.');
        $this->acheter(['appAccountToken' => null])->assertUnprocessable();
        $this->api()->postJson('/api/abonnement/apple', ['signed_transaction' => 'pas-un-jws'])->assertUnprocessable()
            ->assertJsonPath('errors.signed_transaction.0', 'Cette transaction de l’App Store est illisible ou n’est pas signée par Apple.');
        $this->api()->postJson('/api/abonnement/apple', [])->assertUnprocessable()->assertJsonValidationErrors('signed_transaction');

        $this->assertSame(0, DemandeAbonnement::count());
        $this->assertSame('essai', $this->abonnement()->plan);
    }

    public function test_une_periode_deja_finie_ou_remboursee_n_ouvre_rien(): void
    {
        $this->acheter(['expiresDate' => now()->subMinute()->getTimestampMs()])->assertUnprocessable()
            ->assertJsonPath('errors.signed_transaction.0', 'Cet abonnement de l’App Store est déjà terminé : renouvelez-le depuis l’application.');
        $this->acheter(['revocationDate' => now()->getTimestampMs(), 'revocationReason' => 0])->assertUnprocessable()
            ->assertJsonPath('errors.signed_transaction.0', 'Cet achat a été remboursé par Apple : il n’ouvre plus d’abonnement.');

        $this->assertSame(0, DemandeAbonnement::count());
        $this->assertSame('essai', $this->abonnement()->plan);
    }

    public function test_une_signature_falsifiee_ou_une_chaine_inconnue_est_refusee(): void
    {
        $intrus = new FabriqueApple;
        [$entete, , $signature] = explode('.', self::apple()->jws($this->transaction()));
        $retouche = $entete.'.'.FabriqueApple::base64url((string) json_encode($this->transaction(['productId' => 'ngonicaisse.pro.annuel']))).'.'.$signature;

        $this->api()->postJson('/api/abonnement/apple', ['signed_transaction' => $retouche])->assertUnprocessable();
        $this->api()->postJson('/api/abonnement/apple', ['signed_transaction' => $intrus->jws($this->transaction())])->assertUnprocessable();

        $this->notifier('SUBSCRIBED', $this->transaction(), signataire: $intrus)->assertStatus(400);
        $this->postJson('/api/apple/notifications', ['signedPayload' => $retouche])->assertStatus(400);
        $this->postJson('/api/apple/notifications', [])->assertStatus(400);
        // Notification signée par Apple, mais transaction glissée d'ailleurs.
        $this->postJson('/api/apple/notifications', ['signedPayload' => self::apple()->jws([
            'notificationType' => 'SUBSCRIBED',
            'data' => ['bundleId' => 'com.ismaeldev.ngonipay', 'appAppleId' => 6822087487, 'environment' => 'Production', 'signedTransactionInfo' => $intrus->jws($this->transaction())],
            'signedDate' => now()->getTimestampMs(),
        ])])->assertStatus(400);

        $this->assertSame(0, DemandeAbonnement::count());
        $this->assertSame('essai', $this->abonnement()->plan);
    }

    public function test_les_achats_de_test_sandbox_marchent_sans_vente_ni_recette(): void
    {
        ['user' => $exploitant, 'boutique' => $caisse] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'NGONI CAISSE', 'pays' => 'ML', 'telephone' => '76999002',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Ismaël',
        ]);
        $exploitant->forceFill(['est_admin_plateforme' => true])->save();
        config(['ecaisse.boutique_ventes_abonnements' => $caisse->id]);
        // Le soir, un « mois » de Sandbox (cinq minutes) : fini après 16 h, il court jusqu'au lendemain.
        $this->travelTo(now()->setTime(19, 0));

        $this->acheter(['environment' => 'Sandbox', 'productId' => 'ngonicaisse.basic.annuel', 'expiresDate' => now()->addMinutes(5)->getTimestampMs()])
            ->assertOk()
            ->assertJsonPath('data.plan', 'basic')
            ->assertJsonPath('data.est_en_cours', true)
            ->assertJsonPath('data.fin', today()->addDay()->toDateString());

        $demande = DemandeAbonnement::sole();
        $this->assertSame(0, $demande->montant);
        $this->assertSame(CycleFacturation::Annuel, $demande->cycle);
        $this->assertStringContainsString('Sandbox', $demande->note_decision);
        $this->assertSame(0, Vente::withoutGlobalScopes()->where('boutique_id', $caisse->id)->count());

        // Sandbox refusé par la configuration : l'achat ne passe plus.
        config(['apple.environnements' => ['Production']]);
        $this->acheter(['environment' => 'Sandbox', 'transactionId' => '2000000000000009', 'originalTransactionId' => '2000000000000009'])
            ->assertUnprocessable();
    }

    public function test_un_renouvellement_prolonge_et_un_message_en_retard_ne_raccourcit_rien(): void
    {
        $this->acheter()->assertOk();

        // Renouvellement : Apple ne redonne pas forcément le compte, l'abonnement Apple suffit.
        $renouvellement = fn (string $id, int $mois) => $this->transaction([
            'transactionId' => $id, 'transactionReason' => 'RENEWAL', 'appAccountToken' => null,
            'purchaseDate' => now()->addMonths($mois - 1)->getTimestampMs(), 'expiresDate' => now()->addMonths($mois)->getTimestampMs(),
        ]);
        $this->notifier('DID_RENEW', $renouvellement('2000000000000002', 2))->assertOk();
        $this->assertSame(now()->addMonths(2)->toDateString(), $this->abonnement()->fin->toDateString());
        $this->assertSame(2, DemandeAbonnement::where('statut', StatutDemande::Approuvee->value)->count());

        // Le troisième mois arrive avant le deuxième, rejoué en retard.
        $this->notifier('DID_RENEW', $renouvellement('2000000000000004', 4), 'BILLING_RECOVERY')->assertOk();
        $this->notifier('DID_RENEW', $renouvellement('2000000000000003', 3))->assertOk();

        $this->assertSame(now()->addMonths(4)->toDateString(), $this->abonnement()->fin->toDateString());
        $this->assertSame(4, DemandeAbonnement::count());
        $this->assertSame('2000000000000001', $this->abonnement()->apple_transaction_originale);
    }

    public function test_un_achat_annonce_par_apple_seul_est_accorde_au_compte_lie(): void
    {
        $this->notifier('SUBSCRIBED', $this->transaction(['productId' => 'ngonicaisse.basic.mensuel']), 'INITIAL_BUY')->assertOk();

        $this->assertSame('basic', $this->abonnement()->plan);
        $this->assertTrue($this->abonnement()->estEnCours());

        // Sans compte lié ni abonnement Apple connu : accusée, rien d'accordé.
        $this->notifier('SUBSCRIBED', $this->transaction(['transactionId' => '7', 'originalTransactionId' => '7', 'appAccountToken' => null]))->assertOk();
        $this->assertSame(1, DemandeAbonnement::count());
    }

    /** @return array<string, array{string}> */
    public static function finsChezApple(): array
    {
        return ['fin' => ['EXPIRED'], 'grâce écoulée' => ['GRACE_PERIOD_EXPIRED'], 'remboursement' => ['REFUND'], 'partage retiré' => ['REVOKE']];
    }

    #[DataProvider('finsChezApple')]
    public function test_une_fin_chez_apple_arrete_seulement_l_abonnement_qu_il_porte(string $type): void
    {
        $this->acheter()->assertOk();

        // Un autre abonnement Apple (même compte lié) : rien ne s'arrête.
        $this->notifier($type, $this->transaction(['transactionId' => '3000000000000001', 'originalTransactionId' => '3000000000000001']))->assertOk();
        $this->assertTrue($this->abonnement()->estEnCours());

        $this->notifier($type, $this->transaction())->assertOk();

        $abonnement = $this->abonnement();
        $this->assertFalse($abonnement->estEnCours());
        $this->assertSame(today()->subDay()->toDateString(), $abonnement->fin->toDateString());
        $journal = JournalAbonnement::where('user_id', $this->awa->id)->sole();
        $this->assertSame(JournalAbonnement::REVOCATION, $journal->type);
        $this->assertSame('App Store', $journal->par);
        $this->api()->getJson('/api/abonnement')->assertJsonPath('data.est_en_cours', false);
    }

    public function test_un_abonnement_paye_ensuite_par_mobile_money_n_est_pas_coupe_par_apple(): void
    {
        $this->acheter(['productId' => 'ngonicaisse.basic.mensuel'])->assertOk();

        // Payé ensuite par Mobile Money (validé ici à la main) : il n'est plus porté par Apple.
        $service = app(AbonnementService::class);
        $demande = $service->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Annuel, moyen: 'orange_money', prevenir: false);
        $service->approuver($demande, $this->awa);
        $fin = $this->abonnement()->fin->toDateString();
        $this->assertNull($this->abonnement()->apple_transaction_originale);

        $this->notifier('EXPIRED', $this->transaction(['productId' => 'ngonicaisse.basic.mensuel']), 'VOLUNTARY')->assertOk();
        $this->notifier('REFUND', $this->transaction(['productId' => 'ngonicaisse.basic.mensuel']))->assertOk();

        $this->assertTrue($this->abonnement()->estEnCours());
        $this->assertSame('pro', $this->abonnement()->plan);
        $this->assertSame($fin, $this->abonnement()->fin->toDateString());
    }

    public function test_les_autres_notifications_sont_accusees_sans_rien_changer(): void
    {
        $this->notifier('DID_CHANGE_RENEWAL_STATUS', $this->transaction(), 'AUTO_RENEW_DISABLED')->assertOk();
        $this->postJson('/api/apple/notifications', ['signedPayload' => self::apple()->jws([
            'notificationType' => 'TEST',
            'data' => ['bundleId' => 'com.ismaeldev.ngonipay', 'environment' => 'Sandbox'],
            'signedDate' => now()->getTimestampMs(),
        ])])->assertOk();
        // Signée par Apple, mais pour une autre application.
        $this->postJson('/api/apple/notifications', ['signedPayload' => self::apple()->jws([
            'notificationType' => 'SUBSCRIBED',
            'data' => ['bundleId' => 'com.autre.application', 'environment' => 'Production', 'signedTransactionInfo' => self::apple()->jws($this->transaction())],
            'signedDate' => now()->getTimestampMs(),
        ])])->assertOk();

        $this->assertSame(0, DemandeAbonnement::count());
        $this->assertSame('essai', $this->abonnement()->plan);
    }

    public function test_les_offres_donnent_le_produit_app_store_de_chaque_duree(): void
    {
        $plans = collect($this->getJson('/api/plans')->assertOk()->json('data'))->keyBy('code');
        $produits = fn (string $plan) => collect($plans[$plan]['tarifs'])->pluck('apple_produit', 'cycle')->all();

        $this->assertSame('ngonicaisse.pro.mensuel', $produits('pro')['monthly']);
        $this->assertSame('ngonicaisse.pro.annuel', $produits('pro')['yearly']);
        $this->assertSame('ngonicaisse.basic.mensuel', $produits('basic')['monthly']);
        $this->assertSame('ngonicaisse.basic.annuel', $produits('basic')['yearly']);
        foreach (['quarterly', 'biannual'] as $cycle) {
            if (array_key_exists($cycle, $produits('pro'))) {
                $this->assertNull($produits('pro')[$cycle]);
            }
        }
        $this->assertArrayHasKey('montant', $plans['pro']['tarifs'][0]);
    }
}
