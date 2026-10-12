<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\ConditionsUtilisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Versions Windows et macOS : boutons de la vitrine et téléchargement. */
class InstallateursBureauTest extends TestCase
{
    use RefreshDatabase;

    public function test_sans_installateur_pas_de_bouton_et_rien_a_telecharger(): void
    {
        Storage::fake('local');

        $this->get('/')->assertOk()->assertDontSee('Télécharger pour');
        $this->get('/telecharger/windows')->assertNotFound();
        $this->get('/telecharger/linux')->assertNotFound();
    }

    public function test_les_installateurs_deposes_se_telechargent_depuis_la_vitrine(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('telechargements/NgoniCaisse-1.0.0-Windows-Installation.exe', str_repeat('x', 2 * 1048576));
        Storage::disk('local')->put('telechargements/NgoniCaisse-1.0.0-macOS.dmg', 'dmg');

        $this->get('/')->assertOk()
            ->assertSee('Télécharger pour')
            ->assertSee(route('telecharger.bureau', 'windows'))
            ->assertSee(route('telecharger.bureau', 'mac'));
        $this->get('/telecharger')->assertOk()->assertSee('Sur ordinateur');

        $this->get('/telecharger/windows')->assertOk()
            ->assertDownload('NgoniCaisse-1.0.0-Windows-Installation.exe');
        $this->get('/telecharger/mac')->assertOk()->assertDownload('NgoniCaisse-1.0.0-macOS.dmg');
    }

    private function exploitant(): User
    {
        $u = User::factory()->create(['est_admin_plateforme' => true]);
        app(ConditionsUtilisation::class)->accepter($u, request(), 'test');

        return $u;
    }

    /** Envoie [contenu] en morceaux de [taille] octets, comme la page de la console. */
    private function deposer(User $qui, string $systeme, string $version, string $contenu, int $taille = 4): array
    {
        $envoi = (string) Str::uuid();
        $morceaux = str_split($contenu, $taille);
        $reponses = [];
        foreach ($morceaux as $i => $m) {
            $reponses[] = $this->actingAs($qui)->post('/plateforme/applications-ordinateur/envoi', [
                'systeme' => $systeme, 'version' => $version, 'envoi' => $envoi, 'index' => $i, 'total' => count($morceaux),
                'morceau' => UploadedFile::fake()->createWithContent('morceau', $m),
            ], ['Accept' => 'application/json']);
        }

        return $reponses;
    }

    public function test_la_console_depose_un_installateur_par_morceaux_et_la_vitrine_le_propose(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('telechargements/NgoniCaisse-1.0.0-Windows-Installation.exe', 'MZancien');
        $admin = $this->exploitant();

        $this->actingAs($admin)->get('/plateforme/applications-ordinateur')->assertOk()->assertSee('En ligne : version 1.0.0');

        $reponses = $this->deposer($admin, 'windows', '1.0.1', 'MZ'.str_repeat('x', 10));
        $reponses[0]->assertOk()->assertJsonPath('recu', 1);
        $dernier = end($reponses);
        $dernier->assertOk()->assertJsonPath('termine', true);

        Storage::disk('local')->assertExists('telechargements/NgoniCaisse-1.0.1-Windows-Installation.exe');
        Storage::disk('local')->assertMissing('telechargements/NgoniCaisse-1.0.0-Windows-Installation.exe');
        $this->assertSame('MZ'.str_repeat('x', 10), Storage::disk('local')->get('telechargements/NgoniCaisse-1.0.1-Windows-Installation.exe'));
        $this->get('/telecharger/windows')->assertOk()->assertDownload('NgoniCaisse-1.0.1-Windows-Installation.exe');
        $this->actingAs($admin)->get('/plateforme/applications-ordinateur')->assertSee('En ligne : version 1.0.1')->assertSee($admin->name);
    }

    public function test_un_mauvais_fichier_un_morceau_hors_d_ordre_ou_un_commercant_sont_refuses(): void
    {
        Storage::fake('local');
        $admin = $this->exploitant();

        // Pas un .exe (signature MZ), pas un .dmg (bloc « koly » final).
        $this->deposer($admin, 'windows', '1.0.1', 'PK zip')[0]->assertUnprocessable();
        $r = $this->deposer($admin, 'mac', '1.0.1', str_repeat('y', 600), 300);
        end($r)->assertUnprocessable();
        Storage::disk('local')->assertMissing('telechargements/NgoniCaisse-1.0.1-macOS.dmg');

        // Une vraie image disque passe.
        $dmg = str_repeat('d', 100).'koly'.str_repeat("\0", 508);
        $r = $this->deposer($admin, 'mac', '1.0.1', $dmg, 200);
        end($r)->assertOk()->assertJsonPath('termine', true);

        $this->actingAs($admin)->post('/plateforme/applications-ordinateur/envoi', [
            'systeme' => 'windows', 'version' => '1.0.2', 'envoi' => (string) Str::uuid(), 'index' => 3, 'total' => 5,
            'morceau' => UploadedFile::fake()->createWithContent('morceau', 'MZ'),
        ], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('attendu', 0);

        $this->actingAs(User::factory()->create())->post('/plateforme/applications-ordinateur/envoi', [], ['Accept' => 'application/json'])->assertForbidden();
    }
}
