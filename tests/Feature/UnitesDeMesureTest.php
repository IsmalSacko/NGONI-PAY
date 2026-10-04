<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\LigneVente;
use App\Models\Produit;
use App\Models\UniteBoutique;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Quantite;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Vente au poids, à la mesure et au demi : quantités à trois décimales, total
 * de ligne au franc près, stock en fraction, unité figée après la première
 * vente, et les anciennes applications priées de se mettre à jour.
 */
class UnitesDeMesureTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boucherie Awa', 'pays' => 'ML', 'telephone' => '76008201', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        Produit::query()->delete();
        Cache::flush();
    }

    private function api(User $user, ?string $version = '4.10.5')
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $requete = $this->withToken($user->createToken('t')->plainTextToken);

        return $version === null ? $requete : $requete->withHeaders(['X-Appareil-Plateforme' => 'android', 'X-App-Version' => $version]);
    }

    public function test_un_article_au_kilo_se_vend_en_fraction_et_le_total_tombe_au_franc(): void
    {
        $viande = $this->api($this->awa)->postJson('/api/produits', [
            'nom' => 'Viande de bœuf', 'unite' => 'kg', 'prix_vente' => 3500, 'stock' => 10.5, 'seuil_alerte' => 2.5,
        ])->assertCreated()->assertJsonPath('unite', 'kg')->assertJsonPath('stock', 10.5)->json();

        $vente = $this->api($this->awa)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $viande['id'], 'quantite' => 1.25]], 'moyen_paiement' => 'especes',
        ])->assertCreated();

        $this->assertSame(4375, $vente->json('total'));
        $this->assertSame(1.25, $vente->json('lignes.0.quantite'));
        $this->assertSame('kg', $vente->json('lignes.0.unite'));
        $this->assertSame(9.25, Produit::find($viande['id'])->stock);

        // 1/3 kg à 3 500 F = 1 166,67 F : arrondi au franc.
        $this->api($this->awa)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $viande['id'], 'quantite' => 0.333]], 'moyen_paiement' => 'especes',
        ])->assertCreated()->assertJsonPath('total', 1166);
        $this->assertSame(8.917, Produit::find($viande['id'])->stock);
    }

    public function test_le_demi_se_vend_aussi_a_la_piece_et_un_stock_entier_reste_un_entier(): void
    {
        $pain = Produit::create(['nom' => 'Pain', 'prix_vente' => 250, 'taux_tva' => 0, 'stock' => 20, 'seuil_alerte' => 5]);

        $this->api($this->awa)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $pain->id, 'quantite' => 0.5]], 'moyen_paiement' => 'especes',
        ])->assertCreated()->assertJsonPath('total', 125);
        $this->assertSame(19.5, $pain->fresh()->stock);

        $this->api($this->awa)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $pain->id, 'quantite' => 1.5]], 'moyen_paiement' => 'especes',
        ])->assertCreated();
        // Les applications d'avant lisent toujours un entier.
        $this->api($this->awa)->getJson('/api/produits')->assertOk()->assertJsonPath('0.stock', 18);
    }

    public function test_plus_de_trois_decimales_ou_plus_que_le_stock_est_refuse(): void
    {
        $riz = Produit::create(['nom' => 'Riz', 'unite' => 'kg', 'prix_vente' => 600, 'taux_tva' => 0, 'stock' => 2.5]);

        $this->api($this->awa)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $riz->id, 'quantite' => 0.1234]], 'moyen_paiement' => 'especes',
        ])->assertUnprocessable()->assertJsonValidationErrors('lignes.0.quantite');

        $this->api($this->awa)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $riz->id, 'quantite' => 3]], 'moyen_paiement' => 'especes',
        ])->assertUnprocessable()->assertJsonPath('errors.lignes.0', 'Stock insuffisant pour « Riz » (reste 2,5 kg).');

        // Tout le stock, au gramme près : accepté, il reste 0.
        $this->api($this->awa)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $riz->id, 'quantite' => 2.5]], 'moyen_paiement' => 'especes',
        ])->assertCreated();
        $this->assertSame(0, $riz->fresh()->stock);
    }

    public function test_annuler_une_vente_au_poids_rend_la_fraction_au_stock(): void
    {
        $huile = Produit::create(['nom' => 'Huile', 'unite' => 'l', 'prix_vente' => 1200, 'taux_tva' => 0, 'stock' => 5]);
        $vente = $this->api($this->awa)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $huile->id, 'quantite' => 0.75]], 'moyen_paiement' => 'especes',
        ])->assertCreated()->json();
        $this->assertSame(4.25, $huile->fresh()->stock);

        $this->api($this->awa)->postJson("/api/ventes/{$vente['id']}/annuler", ['motif' => 'Erreur'])->assertOk();
        $this->assertSame(5, $huile->fresh()->stock);
    }

    public function test_reception_et_correction_de_stock_en_fraction(): void
    {
        $tissu = Produit::create(['nom' => 'Bazin', 'unite' => 'm', 'prix_vente' => 2000, 'taux_tva' => 0, 'stock' => 0]);

        $this->api($this->awa)->postJson('/api/achats', [
            'lignes' => [['produit_id' => $tissu->id, 'quantite' => 12.5, 'prix_achat' => 1500]], 'montant_paye' => 18750,
        ])->assertCreated()->assertJsonPath('total', 18750);
        $this->assertSame(12.5, $tissu->fresh()->stock);

        $this->api($this->awa)->postJson("/api/produits/{$tissu->id}/ajuster-stock", ['stock' => 11.75, 'motif' => 'Chute'])->assertOk();
        $this->assertSame(11.75, $tissu->fresh()->stock);
        $this->assertSame(-0.75, $tissu->mouvementsStock()->latest('created_at')->latest('id')->first()->quantite);
    }

    public function test_l_unite_se_fige_apres_la_premiere_vente(): void
    {
        $sucre = Produit::create(['nom' => 'Sucre', 'prix_vente' => 700, 'taux_tva' => 0, 'stock' => 10]);
        $this->api($this->awa)->putJson("/api/produits/{$sucre->id}", ['unite' => 'kg'])->assertOk()->assertJsonPath('unite', 'kg');
        $this->api($this->awa)->putJson("/api/produits/{$sucre->id}", ['unite' => 'inconnue'])->assertUnprocessable();

        $this->api($this->awa)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $sucre->id, 'quantite' => 1]], 'moyen_paiement' => 'especes',
        ])->assertCreated();

        $this->api($this->awa)->putJson("/api/produits/{$sucre->id}", ['unite' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('unite');
        // Le reste de la fiche se modifie toujours.
        $this->api($this->awa)->putJson("/api/produits/{$sucre->id}", ['unite' => 'kg', 'prix_vente' => 750])->assertOk();
    }

    public function test_un_conditionnement_s_ecrit_au_pluriel_et_ne_bloque_pas_les_anciennes_versions(): void
    {
        $this->assertSame('2 sacs', Quantite::formater(2, 'sac'));
        $this->assertSame('1,5 sac', Quantite::formater(1.5, 'sac'));
        $this->assertSame('2 kg', Quantite::formater(2, 'kg'));
        $this->assertSame('250 ml', Quantite::formater(250, 'ml'));

        Produit::create(['nom' => 'Riz 25 kg', 'unite' => 'sac', 'prix_vente' => 15000, 'taux_tva' => 0, 'stock' => 12]);
        $this->api($this->awa, '4.10.4')->getJson('/api/produits')->assertOk();
    }

    public function test_une_ancienne_application_doit_se_mettre_a_jour_si_la_boutique_vend_au_poids(): void
    {
        $pain = Produit::create(['nom' => 'Pain', 'prix_vente' => 250, 'taux_tva' => 0, 'stock' => 20]);

        // À la pièce seulement : l'ancienne version passe.
        $this->api($this->awa, '4.10.4')->getJson('/api/produits')->assertOk();

        Produit::create(['nom' => 'Viande', 'unite' => 'kg', 'prix_vente' => 3500, 'taux_tva' => 0, 'stock' => 10]);
        Cache::flush();

        $this->api($this->awa, '4.10.4')->getJson('/api/produits')
            ->assertStatus(426)->assertJsonPath('code', 'MISE_A_JOUR_REQUISE');
        // Une vente déjà faite passe toujours : rien ne se perd.
        $this->api($this->awa, '4.10.4')->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $pain->id, 'quantite' => 1]], 'moyen_paiement' => 'especes',
        ])->assertCreated();
        $this->api($this->awa, '4.10.5+60')->getJson('/api/produits')->assertOk();
        // Sans en-tête de version (back-office, tests) : rien ne change.
        $this->api($this->awa, null)->getJson('/api/produits')->assertOk();
    }

    public function test_une_unite_creee_par_la_boutique_se_choisit_s_accorde_et_se_renomme(): void
    {
        $this->assertSame('3 tas', Quantite::formater(3, 'tas'), 'unité de marché de base');

        $this->api($this->awa)->postJson('/api/unites', ['nom' => 'Boule'])->assertCreated()->assertJsonPath('data.nom', 'boule');
        $this->api($this->awa)->postJson('/api/unites', ['nom' => 'boule'])->assertUnprocessable();
        $this->api($this->awa)->postJson('/api/unites', ['nom' => 'Kilo'])->assertUnprocessable()->assertJsonValidationErrors('nom');
        $this->api($this->awa)->postJson('/api/unites', ['nom' => 'œil', 'pluriel' => 'yeux'])->assertCreated();

        $karite = $this->api($this->awa)->postJson('/api/produits', [
            'nom' => 'Beurre de karité', 'unite' => 'boule', 'prix_vente' => 500, 'stock' => 20,
        ])->assertCreated()->json();
        $this->api($this->awa)->postJson('/api/produits', ['nom' => 'X', 'unite' => 'inconnue', 'prix_vente' => 1])->assertUnprocessable();

        $vente = $this->api($this->awa)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $karite['id'], 'quantite' => 3]], 'moyen_paiement' => 'especes',
        ])->assertCreated();
        $this->assertSame('boule', $vente->json('lignes.0.unite'));
        $this->assertSame('3 boules', Quantite::formater(3, 'boule'));
        $this->assertSame('2 yeux', Quantite::formater(2, 'œil'));

        // Renommée : l'article suit, la vente déjà faite garde son mot.
        $id = UniteBoutique::where('nom', 'boule')->value('id');
        $this->api($this->awa)->putJson("/api/unites/$id", ['nom' => 'boulette'])->assertOk();
        $this->assertSame('boulette', Produit::find($karite['id'])->unite);
        $this->assertSame('boule', LigneVente::first()->unite);

        $this->api($this->awa)->deleteJson("/api/unites/$id")->assertUnprocessable();
        $this->api($this->awa)->getJson('/api/unites')->assertOk()->assertJsonCount(2, 'data');
    }
}
