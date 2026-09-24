<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Produit;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class VenteTest extends TestCase
{
    use RefreshDatabase;

    private function boutiqueAvecAdmin(): array
    {
        $result = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Test',
            'pays' => 'ML',
            'telephone' => '+223 76 00 00 00',
            'email' => null,
            'password' => 'password123',
            'nom_utilisateur' => 'Aminata',
        ]);

        app(TenantContext::class)->setBoutique($result['boutique']->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($result['boutique']->id);

        return $result;
    }

    public function test_une_vente_decremente_le_stock_et_calcule_le_total(): void
    {
        ['boutique' => $boutique, 'user' => $admin] = $this->boutiqueAvecAdmin();

        $produit = Produit::create([
            'nom' => 'Riz parfumé', 'prix_vente' => 4750, 'taux_tva' => 18.00, 'stock' => 10,
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/ventes', [
            'reference_locale' => (string) Str::uuid(),
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 3]],
            'moyen_paiement' => 'especes',
            'montant_recu' => 15000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('total', 14250);
        $response->assertJsonPath('monnaie_rendue', 750);

        $this->assertSame(7, $produit->fresh()->stock);
        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'quantite' => -3,
            'stock_apres' => 7,
        ]);
    }

    public function test_replayer_la_meme_reference_locale_ne_double_pas_la_vente(): void
    {
        ['user' => $admin] = $this->boutiqueAvecAdmin();

        $produit = Produit::create(['nom' => 'Savon', 'prix_vente' => 250, 'taux_tva' => 18.00, 'stock' => 300]);

        $token = $admin->createToken('test')->plainTextToken;
        $reference = (string) Str::uuid();

        $payload = [
            'reference_locale' => $reference,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 3]],
            'moyen_paiement' => 'especes',
            'montant_recu' => 1000,
        ];

        $premiere = $this->withToken($token)->postJson('/api/ventes', $payload)->assertCreated();
        $seconde = $this->withToken($token)->postJson('/api/ventes', $payload)->assertCreated();

        $this->assertSame($premiere->json('id'), $seconde->json('id'));
        $this->assertSame(297, $produit->fresh()->stock);
        $this->assertSame(1, Vente::count());
    }

    public function test_une_vente_est_refusee_si_le_stock_est_insuffisant(): void
    {
        ['user' => $admin] = $this->boutiqueAvecAdmin();

        $produit = Produit::create(['nom' => 'Eau de javel', 'prix_vente' => 500, 'taux_tva' => 18.00, 'stock' => 1]);

        $token = $admin->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 5]],
            'moyen_paiement' => 'especes',
            'montant_recu' => 5000,
        ])->assertUnprocessable();

        $this->assertSame(1, $produit->fresh()->stock);
    }
}
