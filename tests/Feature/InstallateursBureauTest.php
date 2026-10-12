<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
}
