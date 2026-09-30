<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Objectif du mois et série de journées avec vente, dans le tableau de bord. */
class ElanTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    private Produit $riz;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(now()->setDate(2026, 9, 20)->setTime(10, 0));
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Awa', 'pays' => 'ML', 'telephone' => '76008201', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        $this->riz = Produit::create(['nom' => 'Riz', 'prix_vente' => 1000, 'taux_tva' => 0, 'stock' => 500]);
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($this->awa->createToken('t')->plainTextToken);
    }

    /** Une vente de `$quantite` riz, datée de `$jour`. */
    private function vente(string $jour, int $quantite = 1): void
    {
        $id = $this->api()->postJson('/api/ventes', ['lignes' => [['produit_id' => $this->riz->id, 'quantite' => $quantite]], 'moyen_paiement' => 'especes'])
            ->assertCreated()->json('id');
        Vente::withoutBoutiqueScope()->whereKey($id)->update(['jour_affaire' => $jour]);
    }

    public function test_l_objectif_du_mois_se_fixe_et_se_suit(): void
    {
        $this->vente('2026-09-02', 30);
        $this->vente('2026-09-19', 12);
        $this->vente('2026-08-31', 50); // mois précédent : ne compte pas

        $this->api()->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('objectif', ['montant' => null, 'realise' => 42000, 'pourcentage' => null]);

        $this->api()->putJson('/api/boutique/objectif', ['objectif_mensuel' => 60000])->assertOk()->assertJsonPath('data.objectif_mensuel', 60000);
        $this->api()->getJson('/api/dashboard')->assertJsonPath('objectif', ['montant' => 60000, 'realise' => 42000, 'pourcentage' => 70]);

        $this->api()->putJson('/api/boutique/objectif', ['objectif_mensuel' => null])->assertOk()->assertJsonPath('data.objectif_mensuel', null);
        $this->api()->putJson('/api/boutique/objectif', ['objectif_mensuel' => 0])->assertUnprocessable();
    }

    public function test_la_serie_compte_les_jours_d_affilee_et_survit_a_la_matinee(): void
    {
        foreach (['2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19'] as $jour) {
            $this->vente($jour);
        }
        $this->vente('2026-09-14'); // trou le 15 : avant la série

        // Pas encore de vente aujourd'hui (le 20) : la série vaut jusqu'à hier.
        $this->api()->getJson('/api/dashboard')->assertJsonPath('serie', ['jours' => 4, 'aujourdhui' => false]);

        $this->vente('2026-09-20');
        $this->api()->getJson('/api/dashboard')->assertJsonPath('serie', ['jours' => 5, 'aujourdhui' => true]);
    }

    public function test_une_serie_rompue_repart_de_zero(): void
    {
        $this->vente('2026-09-10');
        $this->api()->getJson('/api/dashboard')->assertJsonPath('serie', ['jours' => 0, 'aujourdhui' => false]);
    }
}
