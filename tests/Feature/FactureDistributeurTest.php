<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Client;
use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Money\Montant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Facture « distributeur » : code et NIU du client, BP et PSA de la boutique,
 * référence des articles et frais (enlèvement) figés sur la vente.
 */
class FactureDistributeurTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Montant::oublier();

        ['user' => $this->admin, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Dépôt Boissons', 'pays' => 'ML', 'pays' => 'ML', 'telephone' => '76008202',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Ismael',
        ]);
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        Montant::oublier();

        return $this->withToken($this->admin->createToken('t')->plainTextToken);
    }

    public function test_reglages_de_la_facture_distributeur(): void
    {
        $this->api()->putJson('/api/boutique', [
            'nom' => 'Dépôt Boissons', 'pays' => 'ML', 'boite_postale' => 'BP 1234 Bamako', 'psa_taux' => 2.5, 'modele_facture' => 'distributeur',
        ])->assertOk();
        $b = $this->boutique->fresh();
        $this->assertSame('BP 1234 Bamako', $b->boite_postale);
        $this->assertSame(2.5, $b->psa_taux);
        $this->assertSame('distributeur', $b->modele_facture);

        // Une ancienne app qui n'envoie pas ces champs ne les efface pas.
        $this->api()->putJson('/api/boutique', ['nom' => 'Dépôt Boissons', 'pays' => 'ML'])->assertOk();
        $this->assertSame('distributeur', $this->boutique->fresh()->modele_facture);

        $this->api()->putJson('/api/boutique', ['nom' => 'Dépôt Boissons', 'pays' => 'ML', 'modele_facture' => 'autre'])->assertUnprocessable();
        $this->api()->putJson('/api/boutique', ['nom' => 'Dépôt Boissons', 'pays' => 'ML', 'psa_taux' => 50])->assertUnprocessable();
    }

    public function test_client_et_vente_portent_les_mentions_du_distributeur(): void
    {
        $client = $this->api()->postJson('/api/clients', [
            'nom' => 'Ets Kalia', 'code_client' => 'KAL0217', 'niu' => 'P012345678901Q', 'adresse' => 'Marché central',
        ])->assertCreated()->json('data.id') ?? null;
        $client ??= Client::withoutGlobalScopes()->where('code_client', 'KAL0217')->value('id');
        $this->assertNotNull($client);

        app(TenantContext::class)->setBoutique($this->boutique->id);
        $biere = Produit::create(['nom' => 'Bière 65 cl', 'reference' => 'EXP50C', 'frais_enlevement' => 250, 'prix_vente' => 10000, 'taux_tva' => 19.25, 'stock' => 50]);

        $vente = $this->api()->postJson('/api/ventes', [
            'reference_locale' => (string) Str::uuid(),
            'client_id' => $client,
            'lignes' => [
                ['produit_id' => $biere->id, 'quantite' => 2],
                ['libelle' => 'Frais d’enlèvement', 'prix_unitaire' => 500, 'taux_tva' => 19.25, 'quantite' => 1, 'frais' => true],
            ],
            'moyen_paiement' => 'especes',
            'montant_recu' => 20500,
        ])->assertCreated();

        $vente->assertJsonPath('client.code_client', 'KAL0217')
            ->assertJsonPath('client.niu', 'P012345678901Q')
            ->assertJsonPath('lignes.0.reference', 'EXP50C')
            ->assertJsonPath('lignes.0.frais', false)
            ->assertJsonPath('lignes.1.frais', true);
        $this->assertSame(20500, $vente->json('total'));
        $this->assertSame(250, $biere->fresh()->frais_enlevement);
        $this->assertSame(48.0, (float) $biere->fresh()->stock);
    }
}
