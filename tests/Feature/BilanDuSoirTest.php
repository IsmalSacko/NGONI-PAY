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
use Illuminate\Support\Carbon;
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
        // 20 h à Bamako (UTC) : l'heure du bilan pour une boutique du Mali.
        $this->travelTo(Carbon::parse('2026-09-30 20:00:00', 'UTC'));
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

    public function test_le_bilan_part_a_20_h_a_l_heure_du_pays_de_la_boutique(): void
    {
        $this->boutique->update(['pays' => 'CM']); // Douala : UTC+1
        $this->travelTo(Carbon::parse('2026-09-30 10:00:00', 'UTC'));
        $this->vendre(3000);

        $this->travelTo(Carbon::parse('2026-09-30 20:00:00', 'UTC')); // 21 h à Douala
        $this->artisan('ecaisse:bilan-du-soir');
        $this->assertSame(0, $this->bilans()->count(), 'trop tard à Douala : pas à 21 h');

        $this->travelTo(Carbon::parse('2026-09-30 19:00:00', 'UTC')); // 20 h à Douala
        $this->artisan('ecaisse:bilan-du-soir');
        $this->assertSame(1, $this->bilans()->count());
    }

    public function test_en_france_l_heure_d_ete_est_suivie(): void
    {
        $this->boutique->update(['pays' => 'FR', 'devise' => 'EUR']); // Paris : UTC+2 fin septembre
        $this->travelTo(Carbon::parse('2026-09-30 09:00:00', 'UTC'));
        $this->vendre(3000);

        $this->travelTo(Carbon::parse('2026-09-30 18:00:00', 'UTC'));
        $this->artisan('ecaisse:bilan-du-soir');
        $this->assertSame(1, $this->bilans()->count(), '18 h UTC = 20 h à Paris');
    }

    public function test_rien_en_dehors_de_20_h(): void
    {
        $this->vendre(3000);
        foreach (['12:00', '19:00', '21:00', '23:00'] as $heure) {
            $this->travelTo(Carbon::parse("2026-09-30 {$heure}:00", 'UTC'));
            $this->artisan('ecaisse:bilan-du-soir');
        }
        $this->assertSame(0, $this->bilans()->count());
    }

    public function test_le_lendemain_a_20_h_un_nouveau_bilan(): void
    {
        $this->vendre(3000);
        $this->artisan('ecaisse:bilan-du-soir');
        $this->travelTo(Carbon::parse('2026-10-01 20:00:00', 'UTC'));
        $this->vendre(5000);
        $this->artisan('ecaisse:bilan-du-soir');

        $this->assertSame(2, $this->bilans()->count());
        $this->assertStringContainsString('5 000 F CFA (+67 % par rapport à hier)', $this->bilans()->latest('created_at')->first()->message);
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
