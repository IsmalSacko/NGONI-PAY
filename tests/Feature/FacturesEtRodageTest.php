<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Client;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Factures personnalisées (préfixe, suffixe, année, prochain numéro : jamais
 * un numéro déjà donné) et mode rodage (ventes d'essai supprimables, passage
 * en mode réel qui efface tout sauf les comptes).
 */
class FacturesEtRodageTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    private Produit $riz;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Awa Traoré', 'pays' => 'ML', 'telephone' => '76008201', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        $this->riz = Produit::create(['nom' => 'Riz', 'prix_vente' => 1000, 'prix_achat' => 800, 'taux_tva' => 0, 'stock' => 50]);
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($this->awa->createToken('t')->plainTextToken);
    }

    private function vendre(): array
    {
        return $this->api()->postJson('/api/ventes', ['lignes' => [['produit_id' => $this->riz->id, 'quantite' => 2]], 'moyen_paiement' => 'especes'])
            ->assertCreated()->json();
    }

    private function reglages(array $facture)
    {
        return $this->api()->putJson('/api/boutique', ['nom' => $this->boutique->nom, 'pays' => 'ML', ...$facture]);
    }

    public function test_le_numero_par_defaut_ne_change_pas(): void
    {
        $this->assertSame('EAT-'.now()->format('Y').'-0001', $this->vendre()['numero_facture']);
        $this->assertSame('EAT-'.now()->format('Y').'-0002', $this->vendre()['numero_facture']);
    }

    public function test_prefixe_suffixe_et_sans_annee(): void
    {
        $this->reglages(['facture_prefixe' => 'fac', 'facture_suffixe' => 'ML', 'facture_annee' => false])->assertOk()
            ->assertJsonPath('data.prochaine_facture', 'FAC-0001-ML');

        $this->assertSame('FAC-0001-ML', $this->vendre()['numero_facture']);
        $this->reglages(['facture_prefixe' => 'FAC#'])->assertUnprocessable()->assertJsonValidationErrors('facture_prefixe');
    }

    public function test_un_numero_deja_donne_ne_resert_jamais_mais_un_nouveau_prefixe_repart_a_1(): void
    {
        $this->reglages(['facture_prefixe' => 'FAC', 'facture_prochain_numero' => 457])->assertOk()
            ->assertJsonPath('data.prochaine_facture', 'FAC-'.now()->format('Y').'-0457');
        $this->assertSame('FAC-'.now()->format('Y').'-0457', $this->vendre()['numero_facture']);

        // Revenir en arrière dans la même série : refusé.
        $this->reglages(['facture_prochain_numero' => 1])->assertUnprocessable()->assertJsonValidationErrors('facture_prochain_numero');

        // Nouvelle série : repart à 1, sans doublon possible.
        $this->reglages(['facture_prefixe' => 'FAB'])->assertOk()->assertJsonPath('data.prochaine_facture', 'FAB-'.now()->format('Y').'-0001');
        // Revenir à l'ancien préfixe reprend sa série, après 457.
        $this->reglages(['facture_prefixe' => 'FAC'])->assertOk()->assertJsonPath('data.prochaine_facture', 'FAC-'.now()->format('Y').'-0458');
    }

    public function test_le_rodage_numerote_en_essai_et_les_essais_se_suppriment(): void
    {
        $this->api()->postJson('/api/boutique/rodage')->assertOk()->assertJsonPath('data.mode_rodage', true);

        $vente = $this->vendre();
        $this->assertSame('ESSAI-EAT-'.now()->format('Y').'-0001', $vente['numero_facture']);
        $this->assertTrue((bool) $vente['essai']);
        $this->assertEquals(48, $this->riz->fresh()->stock);

        $this->api()->deleteJson("/api/ventes/{$vente['id']}")->assertOk();
        $this->assertNull(Vente::withoutGlobalScopes()->find($vente['id']));
        $this->assertEquals(50, $this->riz->fresh()->stock, 'le stock revient');
    }

    public function test_pas_de_rodage_ni_de_suppression_avec_de_vraies_ventes(): void
    {
        $vente = $this->vendre();

        $this->api()->deleteJson("/api/ventes/{$vente['id']}")->assertUnprocessable();
        $this->api()->postJson('/api/boutique/rodage')->assertUnprocessable()->assertJsonValidationErrors('rodage');
    }

    public function test_le_passage_en_mode_reel_efface_tout_sauf_les_comptes_et_le_catalogue(): void
    {
        $this->api()->postJson('/api/boutique/rodage')->assertOk();
        $this->vendre();
        Client::create(['nom' => 'Client essai', 'telephone' => '+22370000001']);

        $this->api()->postJson('/api/boutique/mode-reel', ['confirmation' => 'non'])->assertUnprocessable();
        $this->api()->postJson('/api/boutique/mode-reel', ['confirmation' => 'MODE REEL', 'garder_catalogue' => true])
            ->assertOk()->assertJsonPath('data.mode_rodage', false);

        $this->assertSame(0, Vente::withoutGlobalScopes()->where('boutique_id', $this->boutique->id)->count());
        $this->assertSame(0, Client::withoutGlobalScopes()->where('boutique_id', $this->boutique->id)->count());
        $this->assertEquals(0, $this->riz->fresh()->stock, 'le catalogue reste, le stock repart de 0');
        $this->assertNotNull(User::find($this->awa->id));

        // La première vraie facture porte le numéro 1, sans « ESSAI ».
        Produit::withoutGlobalScopes()->whereKey($this->riz->id)->update(['stock' => 10]);
        $this->assertSame('EAT-'.now()->format('Y').'-0001', $this->vendre()['numero_facture']);
    }

    public function test_repartir_de_zero_efface_aussi_les_commandes_du_pressing(): void
    {
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'pressing'])->assertOk();
        $services = collect($this->api()->getJson('/api/services')->json('data'))->pluck('id', 'nom')->all();
        $chemise = $this->api()->postJson('/api/produits', [
            'nom' => 'Chemise', 'prix_vente' => 500, 'taux_tva' => 0,
            'tarifs' => [['service_id' => $services['Lavage + repassage'], 'prix' => 500]],
        ])->assertCreated()->json('id');
        app(TenantContext::class)->setBoutique($this->boutique->id);
        $client = Client::create(['nom' => 'Moussa', 'telephone' => '+22370112233'])->id;
        $this->api()->postJson('/api/commandes-pressing', [
            'client_id' => $client,
            'lignes' => [['produit_id' => $chemise, 'service_id' => $services['Lavage + repassage'], 'quantite' => 2]],
        ])->assertCreated();

        // Les commandes tiennent aux clients : elles partent d'abord, sans erreur.
        $this->api()->postJson('/api/boutique/reinitialiser', ['confirmation' => 'REINITIALISER'])->assertOk();
        $this->assertSame(0, DB::table('commandes_pressing')->where('boutique_id', $this->boutique->id)->count());
        $this->assertSame(0, Client::withoutGlobalScopes()->where('boutique_id', $this->boutique->id)->count());
    }

    public function test_corriger_une_facture_annule_et_remplace_dans_la_meme_operation(): void
    {
        $fautive = $this->vendre(); // 2 riz
        $annee = now()->format('Y');

        $corrigee = $this->api()->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $this->riz->id, 'quantite' => 3]],
            'moyen_paiement' => 'especes',
            'remplace_vente_id' => $fautive['id'],
        ])->assertCreated()->json();

        $this->assertSame("EAT-{$annee}-0002", $corrigee['numero_facture']);
        $this->assertSame("EAT-{$annee}-0001", $corrigee['remplace_numero']);
        $ancienne = $this->api()->getJson("/api/ventes/{$fautive['id']}")->assertOk()->json();
        $this->assertSame('annulee', $ancienne['statut']);
        $this->assertSame("EAT-{$annee}-0002", $ancienne['remplacee_par_numero']);
        $this->assertStringContainsString('remplacée par la facture', $ancienne['motif_annulation']);
        $this->assertEquals(47, $this->riz->fresh()->stock, 'les 2 riz reviennent, les 3 partent');

        // Une facture déjà corrigée ne se corrige plus : rien n'est créé.
        $this->api()->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $this->riz->id, 'quantite' => 1]],
            'moyen_paiement' => 'especes',
            'remplace_vente_id' => $fautive['id'],
        ])->assertUnprocessable();
        $this->assertSame(2, Vente::withoutGlobalScopes()->where('boutique_id', $this->boutique->id)->count());
    }
}
