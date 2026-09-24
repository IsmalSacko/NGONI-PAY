<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Paiement;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Couche de paiement en ligne (PayPal, PayDunya). Aucun appel réseau réel : les
 * réponses des fournisseurs sont simulées d'après leur documentation — un vrai
 * essai en bac à sable avec des clés de test reste à faire (voir le README).
 */
class PaiementEnLigneTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Produit $riz;

    /** @var array<string, mixed> */
    private array $paydunyaConfirmation = ['response_code' => '00', 'status' => 'pending'];

    /** @var array{0: int, 1: array<string, mixed>} */
    private array $paydunyaCreation = [200, [
        'response_code' => '00',
        'response_text' => 'https://paydunya.com/sandbox-checkout/invoice/test_abc',
        'description' => 'Checkout Invoice Created',
        'token' => 'test_abc',
    ]];

    /**
     * États successifs de la commande PayPal : chaque relecture consomme le
     * premier, le dernier reste ensuite.
     *
     * @var list<array<string, mixed>>
     */
    private array $paypalCommandes = [['id' => 'ORD-1', 'status' => 'PAYER_ACTION_REQUIRED']];

    /** @var array{0: int, 1: array<string, mixed>} */
    private array $paypalCapture = [201, [
        'id' => 'ORD-1',
        'status' => 'COMPLETED',
        'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-1', 'status' => 'COMPLETED']]]]],
    ]];

    /** @var array<string, mixed> */
    private array $paypalVerification = ['verification_status' => 'SUCCESS'];

    protected function setUp(): void
    {
        parent::setUp();

        $result = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Test', 'pays' => 'ML', 'telephone' => '+223 76 00 00 00',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Aminata',
        ]);
        $this->admin = $result['user'];

        app(TenantContext::class)->setBoutique($result['boutique']->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($result['boutique']->id);

        $this->riz = Produit::create(['nom' => 'Riz parfumé', 'prix_vente' => 5000, 'taux_tva' => 18.00, 'stock' => 10]);

        config([
            'app.url' => 'https://caisse.example.test',
            'paiements.paydunya.master_key' => 'master-test',
            'paiements.paydunya.private_key' => 'private-test',
            'paiements.paydunya.token' => 'token-test',
            'paiements.paypal.client_id' => 'client-test',
            'paiements.paypal.secret' => 'secret-test',
            'paiements.paypal.webhook_id' => 'WH-1',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'app.paydunya.com/sandbox-api/v1/checkout-invoice/create' => fn () => Http::response($this->paydunyaCreation[1], $this->paydunyaCreation[0]),
            'app.paydunya.com/sandbox-api/v1/checkout-invoice/confirm/*' => fn () => Http::response($this->paydunyaConfirmation),
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'AT-1', 'expires_in' => 32400]),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'ORD-1',
                'status' => 'PAYER_ACTION_REQUIRED',
                'links' => [
                    ['rel' => 'self', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORD-1'],
                    ['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORD-1'],
                ],
            ], 201),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORD-1/capture' => fn () => Http::response($this->paypalCapture[1], $this->paypalCapture[0]),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORD-1' => fn () => Http::response(count($this->paypalCommandes) > 1 ? array_shift($this->paypalCommandes) : $this->paypalCommandes[0]),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => fn () => Http::response($this->paypalVerification),
        ]);
    }

    private function jeton(?User $user = null): string
    {
        return ($user ?? $this->admin)->createToken('test')->plainTextToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function panier(string $moyen, int $quantite = 2, ?string $reference = null): array
    {
        return [
            'reference_locale' => $reference ?? (string) Str::uuid(),
            'lignes' => [['produit_id' => $this->riz->id, 'quantite' => $quantite]],
            'moyen_paiement' => $moyen,
        ];
    }

    private function ouvrir(string $moyen, int $quantite = 2): string
    {
        return $this->withToken($this->jeton())->postJson('/api/paiements', $this->panier($moyen, $quantite))
            ->assertCreated()
            ->json('id');
    }

    private function relire(string $id): TestResponse
    {
        return $this->withToken($this->jeton())->getJson("/api/paiements/{$id}");
    }

    public function test_sans_cles_aucun_fournisseur_nest_propose_et_la_caisse_reste_declarative(): void
    {
        config([
            'paiements.paydunya.master_key' => null,
            'paiements.paypal.client_id' => null,
        ]);

        $this->withToken($this->jeton())->getJson('/api/paiements/fournisseurs')
            ->assertOk()->assertExactJson(['data' => []]);

        $this->withToken($this->jeton())->postJson('/api/paiements', $this->panier('carte'))
            ->assertUnprocessable()->assertJsonValidationErrors('moyen_paiement');

        // Le chemin déclaratif habituel est intact.
        $this->withToken($this->jeton())->postJson('/api/ventes', $this->panier('carte'))->assertCreated();
    }

    public function test_les_fournisseurs_configures_sont_listes_avec_leur_mode(): void
    {
        $this->withToken($this->jeton())->getJson('/api/paiements/fournisseurs')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['moyen_paiement' => 'carte', 'fournisseur' => 'paydunya', 'mode' => 'test'],
                ['moyen_paiement' => 'paypal', 'fournisseur' => 'paypal', 'mode' => 'test'],
            ]]);
    }

    public function test_une_boutique_hors_fcfa_na_pas_de_paiement_en_ligne(): void
    {
        $guinee = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Conakry', 'pays' => 'GN', 'telephone' => '+224 62 00 00 00',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Mamadou',
        ]);

        $this->assertNotContains($guinee['boutique']->devise, ['XOF', 'XAF']);

        $this->withToken($this->jeton($guinee['user']))->getJson('/api/paiements/fournisseurs')
            ->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_un_moyen_declaratif_ne_peut_pas_etre_ouvert_en_ligne(): void
    {
        $this->withToken($this->jeton())->postJson('/api/paiements', $this->panier('orange_money'))
            ->assertUnprocessable()->assertJsonValidationErrors('moyen_paiement');
    }

    public function test_paydunya_ouvre_une_facture_au_total_calcule_par_le_serveur(): void
    {
        $reponse = $this->withToken($this->jeton())->postJson('/api/paiements', $this->panier('carte', 2));

        $reponse->assertCreated()
            ->assertJsonPath('statut', 'en_attente')
            ->assertJsonPath('fournisseur', 'paydunya')
            ->assertJsonPath('montant', 10000)
            ->assertJsonPath('devise_fournisseur', 'XOF')
            ->assertJsonPath('url_paiement', 'https://paydunya.com/sandbox-checkout/invoice/test_abc')
            ->assertJsonPath('vente', null);

        Http::assertSent(function (ClientRequest $requete) {
            if (! str_ends_with($requete->url(), '/sandbox-api/v1/checkout-invoice/create')) {
                return false;
            }

            $corps = $requete->data();

            return $requete->hasHeader('PAYDUNYA-MASTER-KEY', 'master-test')
                && $requete->hasHeader('PAYDUNYA-PRIVATE-KEY', 'private-test')
                && $requete->hasHeader('PAYDUNYA-TOKEN', 'token-test')
                && $corps['invoice']['total_amount'] === 10000
                && $corps['invoice']['channels'] === ['card']
                && $corps['store']['name'] === 'Épicerie Test'
                && str_starts_with($corps['actions']['return_url'], 'https://caisse.example.test/paiements/retour/')
                && $corps['actions']['callback_url'] === 'https://caisse.example.test/api/webhooks/paydunya';
        });

        // Le paiement seul ne crée ni vente ni mouvement de stock.
        $this->assertSame(0, Vente::count());
        $this->assertSame(10, $this->riz->fresh()->stock);
    }

    public function test_le_client_ne_peut_pas_imposer_son_prix(): void
    {
        $this->withToken($this->jeton())->postJson('/api/paiements', $this->panier('carte', 1) + ['montant' => 1, 'total' => 1])
            ->assertCreated()->assertJsonPath('montant', 5000);
    }

    public function test_paydunya_confirme_cree_la_vente_une_seule_fois(): void
    {
        $id = $this->ouvrir('carte', 2);

        $this->relire($id)->assertOk()->assertJsonPath('statut', 'en_attente')->assertJsonPath('vente', null);

        $this->paydunyaConfirmation = ['response_code' => '00', 'status' => 'completed', 'mode' => 'test'];

        $premiere = $this->relire($id);
        $premiere->assertOk()
            ->assertJsonPath('statut', 'confirme')
            ->assertJsonPath('vente.total', 10000)
            ->assertJsonPath('vente.moyen_paiement', 'carte')
            ->assertJsonPath('vente.lignes.0.nom_produit', 'Riz parfumé');

        $seconde = $this->relire($id);
        $seconde->assertJsonPath('vente.id', $premiere->json('vente.id'));

        $this->assertSame(1, Vente::count());
        $this->assertSame(8, $this->riz->fresh()->stock);
        $this->assertDatabaseHas('mouvements_stock', ['produit_id' => $this->riz->id, 'quantite' => -2]);
        $this->assertDatabaseHas('ventes', ['reference_locale' => $id, 'moyen_paiement' => 'carte', 'montant_recu' => null]);
    }

    public function test_paydunya_echec_et_annulation_du_client(): void
    {
        $id = $this->ouvrir('carte');
        $this->paydunyaConfirmation = ['response_code' => '00', 'status' => 'failed', 'fail_reason' => 'Solde insuffisant'];

        $this->relire($id)->assertJsonPath('statut', 'echoue')->assertJsonPath('erreur', 'Solde insuffisant')->assertJsonPath('vente', null);

        // PayDunya délivre un jeton par facture.
        $this->paydunyaCreation[1]['token'] = 'test_def';
        $autre = $this->ouvrir('carte');
        $this->paydunyaConfirmation = ['response_code' => '00', 'status' => 'cancelled'];

        $this->relire($autre)->assertJsonPath('statut', 'annule');
        $this->assertSame(0, Vente::count());
    }

    public function test_rejouer_la_meme_reference_locale_ne_cree_pas_un_second_paiement(): void
    {
        $reference = (string) Str::uuid();

        $premier = $this->withToken($this->jeton())->postJson('/api/paiements', $this->panier('carte', 1, $reference))->assertCreated();
        $second = $this->withToken($this->jeton())->postJson('/api/paiements', $this->panier('carte', 1, $reference))->assertCreated();

        $this->assertSame($premier->json('id'), $second->json('id'));
        $this->assertSame(1, Paiement::count());
        Http::assertSentCount(1);
    }

    public function test_un_fournisseur_en_panne_ne_laisse_pas_de_paiement_actif(): void
    {
        $this->paydunyaCreation = [503, []];

        $this->withToken($this->jeton())->postJson('/api/paiements', $this->panier('carte'))
            ->assertStatus(502)->assertJsonPath('message', 'PayDunya rencontre une erreur. Réessayez dans un instant.');

        $this->assertDatabaseHas('paiements', ['statut' => 'echoue']);
        $this->assertSame(0, Vente::count());

        // Le chemin déclaratif reste disponible pour encaisser quand même.
        $this->withToken($this->jeton())->postJson('/api/ventes', $this->panier('carte'))->assertCreated();
    }

    public function test_un_refus_de_creation_par_paydunya_est_signale(): void
    {
        $this->paydunyaCreation = [200, ['response_code' => '1001', 'response_text' => 'Clés invalides']];

        $this->withToken($this->jeton())->postJson('/api/paiements', $this->panier('carte'))
            ->assertStatus(502)->assertJsonPath('message', 'PayDunya a refusé la création du paiement.');
    }

    public function test_webhook_paydunya_authentique_cree_la_vente_sans_la_tablette(): void
    {
        $id = $this->ouvrir('carte', 3);
        $this->paydunyaConfirmation = ['response_code' => '00', 'status' => 'completed'];

        $this->post('/api/webhooks/paydunya', ['data' => [
            'hash' => hash('sha512', 'master-test'),
            'status' => 'completed',
            'invoice' => ['token' => 'test_abc'],
        ]])->assertOk();

        $this->assertDatabaseHas('paiements', ['id' => $id, 'statut' => 'confirme']);
        $this->assertSame(1, Vente::withoutBoutiqueScope()->where('reference_locale', $id)->count());
        $this->assertSame(7, $this->riz->fresh()->stock);

        // Rejeu du même webhook : rien de plus.
        $this->post('/api/webhooks/paydunya', ['data' => [
            'hash' => hash('sha512', 'master-test'),
            'invoice' => ['token' => 'test_abc'],
        ]])->assertOk();
        $this->assertSame(1, Vente::withoutBoutiqueScope()->count());
        $this->assertSame(7, $this->riz->fresh()->stock);
    }

    public function test_webhook_paydunya_avec_un_mauvais_hash_est_rejete(): void
    {
        $id = $this->ouvrir('carte');
        $this->paydunyaConfirmation = ['response_code' => '00', 'status' => 'completed'];

        $this->post('/api/webhooks/paydunya', ['data' => ['hash' => 'faux', 'invoice' => ['token' => 'test_abc']]])->assertStatus(400);
        $this->post('/api/webhooks/paydunya', ['data' => ['invoice' => ['token' => 'test_abc']]])->assertStatus(400);

        $this->assertDatabaseHas('paiements', ['id' => $id, 'statut' => 'en_attente']);
        $this->assertSame(0, Vente::withoutBoutiqueScope()->count());
    }

    public function test_paypal_facture_en_euros_et_renvoie_le_lien_du_payeur(): void
    {
        $reponse = $this->withToken($this->jeton())->postJson('/api/paiements', $this->panier('paypal', 1));

        // 5 000 FCFA / 655,957 = 7,6225… EUR, arrondi au centime supérieur.
        $reponse->assertCreated()
            ->assertJsonPath('fournisseur', 'paypal')
            ->assertJsonPath('montant', 5000)
            ->assertJsonPath('devise_fournisseur', 'EUR')
            ->assertJsonPath('montant_fournisseur', '7.63')
            ->assertJsonPath('url_paiement', 'https://www.sandbox.paypal.com/checkoutnow?token=ORD-1');

        Http::assertSent(function (ClientRequest $requete) {
            if (! str_ends_with($requete->url(), '/v2/checkout/orders') || $requete->method() !== 'POST') {
                return false;
            }

            $corps = $requete->data();
            $unite = $corps['purchase_units'][0];
            $contexte = $corps['payment_source']['paypal']['experience_context'];

            return $requete->hasHeader('Authorization', 'Bearer AT-1')
                && $requete->hasHeader('PayPal-Request-Id')
                && $corps['intent'] === 'CAPTURE'
                && $unite['amount'] === ['currency_code' => 'EUR', 'value' => '7.63']
                && $unite['custom_id'] === $unite['reference_id']
                && $contexte['user_action'] === 'PAY_NOW'
                && $contexte['shipping_preference'] === 'NO_SHIPPING'
                && str_starts_with($contexte['return_url'], 'https://caisse.example.test/paiements/retour/');
        });

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/v1/oauth2/token') && $r->hasHeader('Authorization', 'Basic '.base64_encode('client-test:secret-test')));
    }

    public function test_paypal_approuve_est_capture_puis_la_vente_est_creee(): void
    {
        $id = $this->ouvrir('paypal', 2);

        $this->relire($id)->assertJsonPath('statut', 'en_attente');
        Http::assertNotSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/capture'));

        $this->paypalCommandes = [['id' => 'ORD-1', 'status' => 'APPROVED']];

        $this->relire($id)->assertJsonPath('statut', 'confirme')
            ->assertJsonPath('vente.total', 10000)
            ->assertJsonPath('vente.moyen_paiement', 'paypal');

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/v2/checkout/orders/ORD-1/capture') && $r->hasHeader('PayPal-Request-Id', "capture-{$id}"));
        $this->assertSame(1, Vente::count());
        $this->assertSame(8, $this->riz->fresh()->stock);
    }

    public function test_paypal_capture_deja_faite_ailleurs_est_relue_sans_double_vente(): void
    {
        $id = $this->ouvrir('paypal', 1);

        // La tablette voit la commande approuvée et tente la capture, mais un
        // webhook simultané l'a déjà faite : PayPal répond ORDER_ALREADY_CAPTURED.
        $this->paypalCommandes = [
            ['id' => 'ORD-1', 'status' => 'APPROVED'],
            ['id' => 'ORD-1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-1', 'status' => 'COMPLETED']]]]]],
        ];
        $this->paypalCapture = [422, ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]]];

        $this->relire($id)->assertJsonPath('statut', 'confirme');
        $this->relire($id)->assertJsonPath('statut', 'confirme');
        $this->assertSame(1, Vente::count());
        $this->assertSame(9, $this->riz->fresh()->stock);
    }

    public function test_paypal_instrument_refuse_marque_le_paiement_echoue(): void
    {
        $id = $this->ouvrir('paypal', 1);

        $this->paypalCommandes = [['id' => 'ORD-1', 'status' => 'APPROVED']];
        $this->paypalCapture = [422, ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED']]]];

        $this->relire($id)->assertJsonPath('statut', 'echoue')->assertJsonPath('vente', null);
        $this->assertSame(0, Vente::count());
    }

    public function test_webhook_paypal_verifie_puis_finalise(): void
    {
        $id = $this->ouvrir('paypal', 1);
        $this->paypalCommandes = [['id' => 'ORD-1', 'status' => 'APPROVED']];

        $evenement = json_encode([
            'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => ['id' => 'ORD-1', 'purchase_units' => [['custom_id' => $id]]],
        ]);

        $this->call('POST', '/api/webhooks/paypal', [], [], [], $this->entetesPaypal(), $evenement)->assertOk();

        $this->assertDatabaseHas('paiements', ['id' => $id, 'statut' => 'confirme']);
        $this->assertSame(1, Vente::withoutBoutiqueScope()->count());

        // Le corps brut est transmis tel quel dans la vérification.
        Http::assertSent(function (ClientRequest $r) use ($evenement) {
            return str_ends_with($r->url(), '/v1/notifications/verify-webhook-signature')
                && str_contains($r->body(), '"webhook_id":"WH-1"')
                && str_ends_with($r->body(), '"webhook_event":'.$evenement.'}');
        });
    }

    public function test_webhook_paypal_non_verifie_est_rejete(): void
    {
        $id = $this->ouvrir('paypal', 1);
        $this->paypalCommandes = [['id' => 'ORD-1', 'status' => 'APPROVED']];

        $evenement = json_encode(['event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'ORD-1']]);

        $this->paypalVerification = ['verification_status' => 'FAILURE'];
        $this->call('POST', '/api/webhooks/paypal', [], [], [], $this->entetesPaypal(), $evenement)->assertStatus(400);

        // Sans identifiant de webhook configuré, aucune notification n'est crue.
        $this->paypalVerification = ['verification_status' => 'SUCCESS'];
        config(['paiements.paypal.webhook_id' => null]);
        $this->call('POST', '/api/webhooks/paypal', [], [], [], $this->entetesPaypal(), $evenement)->assertStatus(400);

        $this->assertDatabaseHas('paiements', ['id' => $id, 'statut' => 'en_attente']);
        $this->assertSame(0, Vente::withoutBoutiqueScope()->count());
    }

    public function test_stock_epuise_pendant_le_paiement_garde_le_paiement_confirme_avec_son_erreur(): void
    {
        $id = $this->ouvrir('carte', 8);

        // Un autre client vide le rayon pendant que celui-ci paie.
        $this->riz->update(['stock' => 3]);
        $this->paydunyaConfirmation = ['response_code' => '00', 'status' => 'completed'];

        $reponse = $this->relire($id)->assertOk();
        $reponse->assertJsonPath('statut', 'confirme')->assertJsonPath('vente', null);
        $this->assertStringContainsString('Stock insuffisant', $reponse->json('erreur'));
        $this->assertSame(0, Vente::count());
        $this->assertSame(3, $this->riz->fresh()->stock);

        // Réapprovisionné : la relecture suivante finalise la vente.
        $this->riz->update(['stock' => 10]);

        $this->relire($id)->assertJsonPath('statut', 'confirme')->assertJsonPath('erreur', null)->assertJsonPath('vente.total', 40000);
        $this->assertSame(2, $this->riz->fresh()->stock);
        $this->assertSame(1, Vente::count());
    }

    public function test_un_prix_modifie_pendant_le_paiement_ne_cree_pas_une_vente_au_mauvais_montant(): void
    {
        $id = $this->ouvrir('carte', 2);

        $this->riz->update(['prix_vente' => 6000]);
        $this->paydunyaConfirmation = ['response_code' => '00', 'status' => 'completed'];

        $reponse = $this->relire($id)->assertJsonPath('statut', 'confirme')->assertJsonPath('vente', null);
        $this->assertStringContainsString('prix ont changé', $reponse->json('erreur'));
        $this->assertSame(0, Vente::count());
        $this->assertSame(10, $this->riz->fresh()->stock);
    }

    public function test_annuler_un_paiement_en_attente(): void
    {
        $id = $this->ouvrir('carte');

        $this->withToken($this->jeton())->postJson("/api/paiements/{$id}/annuler")
            ->assertOk()->assertJsonPath('statut', 'annule');

        $this->relire($id)->assertJsonPath('statut', 'annule');
        $this->assertSame(0, Vente::count());
    }

    public function test_annuler_un_paiement_deja_paye_ne_le_perd_pas(): void
    {
        $id = $this->ouvrir('carte', 1);
        $this->paydunyaConfirmation = ['response_code' => '00', 'status' => 'completed'];

        $this->withToken($this->jeton())->postJson("/api/paiements/{$id}/annuler")
            ->assertOk()->assertJsonPath('statut', 'confirme')->assertJsonPath('vente.total', 5000);
    }

    public function test_un_client_qui_paie_apres_lannulation_est_quand_meme_enregistre(): void
    {
        $id = $this->ouvrir('carte', 1);

        $this->withToken($this->jeton())->postJson("/api/paiements/{$id}/annuler")->assertJsonPath('statut', 'annule');

        $this->paydunyaConfirmation = ['response_code' => '00', 'status' => 'completed'];
        $this->post('/api/webhooks/paydunya', ['data' => ['hash' => hash('sha512', 'master-test'), 'invoice' => ['token' => 'test_abc']]])->assertOk();

        $this->assertDatabaseHas('paiements', ['id' => $id, 'statut' => 'confirme']);
        $this->assertSame(1, Vente::withoutBoutiqueScope()->count());
    }

    public function test_un_paiement_est_invisible_pour_une_autre_boutique(): void
    {
        $id = $this->ouvrir('carte');

        $autre = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Autre boutique', 'pays' => 'SN', 'telephone' => '+221 77 000 00 00',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);

        // Le garde d'authentification mémorise l'utilisateur d'une requête à
        // l'autre au sein d'un même test : on l'oublie pour changer de compte.
        $this->app['auth']->forgetGuards();

        $this->withToken($this->jeton($autre['user']))->getJson("/api/paiements/{$id}")->assertNotFound();
        $this->withToken($this->jeton($autre['user']))->postJson("/api/paiements/{$id}/annuler")->assertNotFound();
    }

    public function test_les_routes_de_paiement_exigent_lauthentification_et_le_droit_de_vendre(): void
    {
        $this->getJson('/api/paiements/fournisseurs')->assertUnauthorized();
        $this->postJson('/api/paiements', $this->panier('carte'))->assertUnauthorized();
    }

    public function test_la_page_de_retour_du_client_synchronise_et_confirme(): void
    {
        $id = $this->ouvrir('carte', 1);

        $this->get("/paiements/retour/{$id}")->assertOk()->assertSee('Paiement en cours')->assertSee('http-equiv="refresh"', false);

        $this->paydunyaConfirmation = ['response_code' => '00', 'status' => 'completed'];

        $this->get("/paiements/retour/{$id}")->assertOk()->assertSee('Paiement reçu')->assertDontSee('http-equiv="refresh"', false);
        $this->assertSame(1, Vente::withoutBoutiqueScope()->count());

        $this->get('/paiements/retour/'.Str::uuid())->assertNotFound();
    }

    public function test_la_page_dannulation_naffiche_pas_de_relecture(): void
    {
        $id = $this->ouvrir('carte', 1);
        $this->paydunyaConfirmation = ['response_code' => '00', 'status' => 'completed'];

        $this->get("/paiements/retour/{$id}?annule=1")->assertOk()->assertSee('Paiement annulé');
        $this->assertSame(0, Vente::withoutBoutiqueScope()->count());
    }

    /**
     * @return array<string, string>
     */
    private function entetesPaypal(): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
            'HTTP_PAYPAL-TRANSMISSION-ID' => 'tx-1',
            'HTTP_PAYPAL-TRANSMISSION-SIG' => 'sig',
            'HTTP_PAYPAL-TRANSMISSION-TIME' => '2026-09-25T10:00:00Z',
        ];
    }
}
