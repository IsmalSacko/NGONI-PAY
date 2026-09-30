<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\MessageCompteMail;
use App\Models\Boutique;
use App\Models\NotificationApp;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Chaque soir, le propriétaire reçoit sa journée : ventes, encaissé, écart
 * avec la veille. Une fois par jour, rien sans vente, rien s'il l'a coupé.
 */
class BilanDuSoirTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Awa', 'pays' => 'ML', 'telephone' => '76008201', 'email' => 'awa@example.com',
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
    }

    private function api(User $user)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($user->createToken('t')->plainTextToken);
    }

    private function vendre(int $montant, ?string $jour = null): void
    {
        app(TenantContext::class)->setBoutique($this->boutique->id);
        $produit = Produit::create(['nom' => 'Article '.$montant, 'prix_vente' => $montant, 'taux_tva' => 0, 'stock' => 10]);
        $id = $this->api($this->awa)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]], 'moyen_paiement' => 'especes',
        ])->assertCreated()->json('id');
        if ($jour !== null) {
            Vente::withoutBoutiqueScope()->whereKey($id)->update(['jour_affaire' => $jour]);
        }
    }

    private function bilans()
    {
        return NotificationApp::where('user_id', $this->awa->id)->where('type', 'bilan');
    }

    public function test_le_proprietaire_recoit_sa_journee_et_l_ecart_avec_la_veille(): void
    {
        $this->vendre(10000, today()->subDay()->toDateString());
        $this->vendre(7000);
        $this->vendre(5000);

        $this->artisan('ecaisse:bilan-du-soir')->assertSuccessful();

        $bilan = $this->bilans()->sole();
        $this->assertSame('Bilan du jour — Pressing Awa', $bilan->titre);
        $this->assertSame('Aujourd’hui : 2 ventes · 12 000 F CFA (+20 % par rapport à hier).', $bilan->message);
        $this->assertSame('/pilotage', $bilan->lien);
        Mail::assertNotSent(MessageCompteMail::class);
    }

    public function test_une_fois_par_jour_et_rien_sans_vente(): void
    {
        $this->artisan('ecaisse:bilan-du-soir')->assertSuccessful();
        $this->assertSame(0, $this->bilans()->count(), 'journée sans vente');

        $this->vendre(3000);
        $this->artisan('ecaisse:bilan-du-soir');
        $this->artisan('ecaisse:bilan-du-soir');
        $this->assertSame(1, $this->bilans()->count());
        $this->assertStringNotContainsString('hier', $this->bilans()->sole()->message, 'pas d’écart sans vente la veille');
    }

    public function test_le_commercant_peut_couper_le_bilan(): void
    {
        $this->vendre(3000);
        $this->api($this->awa)->putJson('/api/moi/preferences', ['bilan_quotidien' => false])
            ->assertOk()->assertJsonPath('user.bilan_quotidien', false);

        $this->artisan('ecaisse:bilan-du-soir');

        $this->assertSame(0, $this->bilans()->count());
        $this->api($this->awa)->getJson('/api/moi')->assertJsonPath('user.bilan_quotidien', false);
    }

    public function test_plusieurs_boutiques_tiennent_dans_un_seul_message(): void
    {
        $this->vendre(4000);
        $this->awa->abonnement()->update(['plan' => 'pro', 'fin' => today()->addMonth()->toDateString()]);
        $deuxieme = app(BoutiqueRegistrationService::class)->ajouterBoutique($this->awa->fresh(), ['nom' => 'Annexe Awa', 'pays' => 'ML']);
        app(TenantContext::class)->setBoutique($deuxieme->id);
        $produit = Produit::create(['nom' => 'Savon', 'prix_vente' => 1500, 'taux_tva' => 0, 'stock' => 5]);
        $this->api($this->awa)->withHeader('X-Boutique', $deuxieme->id)->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 2]], 'moyen_paiement' => 'especes',
        ])->assertCreated();

        $this->artisan('ecaisse:bilan-du-soir');

        $bilan = $this->bilans()->sole();
        $this->assertSame('Bilan du jour de vos boutiques', $bilan->titre);
        $this->assertSame("Annexe Awa : 1 vente · 3 000 F CFA\nPressing Awa : 1 vente · 4 000 F CFA", $bilan->message);
    }
}
