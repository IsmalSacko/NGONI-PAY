<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\BilanMensuelMail;
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

/** Le bilan du mois en PDF : à la demande, et le 1er du mois au propriétaire. */
class BilanMensuelTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(now()->setDate(2026, 9, 15)->setTime(10, 0));
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Awa', 'pays' => 'ML', 'telephone' => '76008201', 'email' => 'awa@example.com',
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        $riz = Produit::create(['nom' => 'Riz', 'prix_vente' => 12000, 'prix_achat' => 9000, 'taux_tva' => 0, 'stock' => 50]);
        $this->api()->postJson('/api/ventes', ['lignes' => [['produit_id' => $riz->id, 'quantite' => 2]], 'moyen_paiement' => 'especes'])->assertCreated();
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($this->awa->createToken('t')->plainTextToken);
    }

    public function test_le_pdf_du_mois_se_telecharge(): void
    {
        $reponse = $this->api()->get('/api/rapports/mensuel?mois=2026-09')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="bilan-epicerie-awa-2026-09.pdf"');

        $this->assertStringStartsWith('%PDF', $reponse->getContent());
        $this->api()->getJson('/api/rapports/mensuel?mois=septembre')->assertUnprocessable()
            ->assertJsonPath('errors.mois.0', 'Indiquez le mois au format AAAA-MM.');
    }

    public function test_le_1er_du_mois_le_proprietaire_recoit_le_bilan_une_fois(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(8, 0));

        $this->artisan('ecaisse:bilan-mensuel')->assertSuccessful();
        $this->artisan('ecaisse:bilan-mensuel')->assertSuccessful();

        $avis = NotificationApp::where('user_id', $this->awa->id)->where('type', 'bilan_mensuel')->sole();
        $this->assertSame('Votre bilan de septembre 2026 — Épicerie Awa', $avis->titre);
        $this->assertSame('/pilotage', $avis->lien);
        Mail::assertSent(BilanMensuelMail::class, 1);
        Mail::assertSent(BilanMensuelMail::class, function (BilanMensuelMail $mail): bool {
            $pj = $mail->attachments()[0];

            return $mail->hasTo('awa@example.com') && $pj->as === 'bilan-epicerie-awa-2026-09.pdf';
        });
    }

    public function test_rien_pour_une_boutique_sans_vente_le_mois_precedent(): void
    {
        Vente::withoutBoutiqueScope()->update(['jour_affaire' => '2026-08-10']);
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(8, 0));

        $this->artisan('ecaisse:bilan-mensuel')->assertSuccessful();

        $this->assertSame(0, NotificationApp::where('type', 'bilan_mensuel')->count());
        Mail::assertNotSent(BilanMensuelMail::class);
    }
}
