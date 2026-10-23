<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\NotificationApp;
use App\Models\SessionCaisse;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Services\Depenses;
use App\Services\SessionCaisseService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Caisse oubliée ouverte : rappel à 23 h, fermeture sans comptage à 4 h, à
 * l'heure du pays, datée de la dernière opération.
 */
class CaissesOublieesTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // Niger : UTC+1.
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Awa', 'pays' => 'NE', 'telephone' => '90008601', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(AbonnementService::class)->accorder($this->awa, 'pro', now()->addMonth(), $this->awa);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function passage(string $utc): array
    {
        return app(SessionCaisseService::class)->passageHoraire(Carbon::parse($utc));
    }

    public function test_rappel_a_23_h_puis_fermeture_a_4_h_datee_de_la_derniere_operation(): void
    {
        $sessions = app(SessionCaisseService::class);
        Carbon::setTestNow('2026-10-23 07:00:00'); // 8 h à Niamey
        $s = $sessions->ouvrir($this->awa, 5000);
        Carbon::setTestNow('2026-10-23 20:47:00'); // 21 h 47 : la dernière opération
        app(Depenses::class)->depenser(['libelle' => 'Pain', 'categorie' => 'autre', 'montant' => 500], $this->awa);
        Carbon::setTestNow();

        // 22 h à Niamey : rien encore.
        $this->assertSame(['rappels' => 0, 'fermees' => 0], $this->passage('2026-10-23 21:00:00'));
        // 23 h : un rappel, une seule fois.
        $this->assertSame(['rappels' => 1, 'fermees' => 0], $this->passage('2026-10-23 22:00:00'));
        $this->assertSame(['rappels' => 0, 'fermees' => 0], $this->passage('2026-10-23 22:30:00'));
        $this->assertSame(1, NotificationApp::where('user_id', $this->awa->id)->where('type', 'caisse')->count());
        // Minuit, 1 h, 3 h : la caisse reste ouverte (ventes de la nuit).
        $this->assertSame(['rappels' => 0, 'fermees' => 0], $this->passage('2026-10-24 02:00:00'));

        // 4 h : fermée, sans comptage, datée de 21 h 47.
        $this->assertSame(['rappels' => 0, 'fermees' => 1], $this->passage('2026-10-24 03:00:00'));
        $s = SessionCaisse::findOrFail($s->id);
        $this->assertSame('fermee', $s->statut);
        $this->assertTrue($s->fermee_auto);
        $this->assertNull($s->fond_final);
        $this->assertNull($s->ecart);
        $this->assertSame('2026-10-23 20:47:00', $s->fermee_le->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(SessionCaisseService::NOTE_AUTO, $s->notes);
        // Une fermeture non comptée ne propose pas de fond à la prochaine ouverture.
        $this->assertNull($sessions->derniereFermeture());
    }

    public function test_une_caisse_ouverte_ce_matin_n_est_pas_fermee(): void
    {
        Carbon::setTestNow('2026-10-24 06:00:00'); // 7 h à Niamey, après 4 h
        $s = app(SessionCaisseService::class)->ouvrir($this->awa, 0);
        Carbon::setTestNow();

        $this->assertSame(['rappels' => 0, 'fermees' => 0], $this->passage('2026-10-24 10:00:00'));
        $this->assertSame('ouverte', SessionCaisse::findOrFail($s->id)->statut);
    }

    public function test_une_caisse_endormie_n_est_ni_rappelee_ni_fermee(): void
    {
        Carbon::setTestNow('2026-10-10 08:00:00');
        $s = app(SessionCaisseService::class)->ouvrir($this->awa, 0);
        Carbon::setTestNow();

        // Rien depuis le 10 : ni rappel le 23 au soir, ni fermeture à 4 h.
        $this->assertSame(['rappels' => 0, 'fermees' => 0], $this->passage('2026-10-23 22:00:00'));
        $this->assertSame(['rappels' => 0, 'fermees' => 0], $this->passage('2026-10-24 03:00:00'));
        $this->assertSame(0, NotificationApp::where('type', 'caisse')->count());
        $this->assertSame('ouverte', SessionCaisse::findOrFail($s->id)->statut);
    }

    public function test_une_vieille_caisse_qui_sert_encore_se_ferme_a_4_h_mais_jamais_en_journee(): void
    {
        $sessions = app(SessionCaisseService::class);
        Carbon::setTestNow('2026-10-10 08:00:00');
        $s = $sessions->ouvrir($this->awa, 0);
        Carbon::setTestNow('2026-10-23 19:30:00'); // une dépense ce soir : la caisse sert encore
        app(Depenses::class)->depenser(['libelle' => 'Sachets', 'categorie' => 'autre', 'montant' => 300], $this->awa);
        Carbon::setTestNow();

        // En pleine journée, rien ne se ferme.
        $this->assertSame(0, $this->passage('2026-10-23 15:00:00')['fermees']);
        $this->assertSame(['rappels' => 0, 'fermees' => 1], $this->passage('2026-10-24 03:00:00'));
        $this->assertSame('2026-10-23 19:30:00', SessionCaisse::findOrFail($s->id)->fermee_le->utc()->format('Y-m-d H:i:s'));
    }
}
