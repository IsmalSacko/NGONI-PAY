<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteInstallableTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_site_installe_s_ouvre_sur_la_connexion_ou_l_espace_du_compte(): void
    {
        $this->get('/espace')->assertRedirect(route('connexion'));

        ['user' => $awa] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        $this->actingAs($awa)->get('/espace')->assertRedirect(route('tableau-de-bord'));

        $exploitant = User::create(['name' => 'Ismael', 'phone' => '+33605758494', 'password' => 'password123']);
        $exploitant->forceFill(['est_admin_plateforme' => true])->save();
        $this->actingAs($exploitant)->get('/espace')->assertRedirect('/plateforme');
    }

    public function test_le_manifeste_rend_le_site_installable(): void
    {
        $manifeste = json_decode((string) file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['/espace', '/espace', 'standalone'], [$manifeste['id'], $manifeste['start_url'], $manifeste['display']]);
        $tailles = array_column($manifeste['icons'], 'sizes');
        $this->assertContains('192x192', $tailles);
        $this->assertContains('512x512', $tailles);
        $this->assertContains('maskable', array_column($manifeste['icons'], 'purpose'));
        foreach ([...$manifeste['icons'], ...array_merge(...array_column($manifeste['shortcuts'], 'icons'))] as $icone) {
            $this->assertFileExists(public_path(ltrim($icone['src'], '/')));
        }
        foreach ($manifeste['shortcuts'] as $raccourci) {
            $this->assertNotSame(404, $this->get($raccourci['url'])->getStatusCode(), $raccourci['url']);
        }
    }

    public function test_le_service_worker_ne_sert_que_la_page_hors_connexion(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("event.request.mode !== 'navigate'", $sw, 'seules les navigations sont interceptées');
        $this->assertStringContainsString('/hors-ligne.html', $sw);
        $this->assertFileExists(public_path('hors-ligne.html'));
    }

    public function test_chaque_page_enregistre_le_service_worker_et_propose_l_installation(): void
    {
        ['user' => $awa] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);

        $this->get('/connexion')->assertOk()->assertSee("register('/sw.js')", false)->assertSee('rel="manifest"', false);
        $this->actingAs($awa)->get('/tableau-de-bord')->assertOk()->assertSee('data-installer', false);
    }
}
