<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Annonce;
use App\Models\NotificationApp;
use App\Services\BoutiqueRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicationPlayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['mobile.jeton_publication' => 'secret-ci', 'mobile.latest_version' => '3.0.1', 'mobile.delai_annonce_heures' => 3]);
        app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Chez Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        Annonce::create(['type' => 'mise_a_jour', 'titre' => 'x', 'message' => 'x', 'version' => '3.0.1', 'audience' => 'tous', 'statut' => 'envoyee']);
    }

    private function signaler(string $version, ?string $jeton = 'secret-ci', ?string $nouveautes = null)
    {
        return $this->withToken((string) $jeton)->postJson('/api/publication-play', array_filter(['version' => $version, 'nouveautes' => $nouveautes]));
    }

    public function test_sans_le_bon_jeton_rien_n_est_accepte(): void
    {
        $this->signaler('4.1.0', 'mauvais')->assertForbidden();
        $this->signaler('4.1.0', null)->assertForbidden();

        config(['mobile.jeton_publication' => null]);
        $this->signaler('4.1.0', '')->assertForbidden();

        $this->assertNull(Annonce::where('version', '4.1.0')->first());
    }

    public function test_sans_delai_la_version_vue_sur_le_play_store_est_annoncee_tout_de_suite(): void
    {
        config(['mobile.delai_annonce_heures' => 0]);

        $this->signaler('4.2.0', nouveautes: 'Aide et tutoriels.')->assertStatus(202);

        $this->assertSame('envoyee', Annonce::where('version', '4.2.0')->sole()->statut);
        $this->assertSame(1, NotificationApp::where('type', 'mise_a_jour')->count());
        $this->getJson('/api/app-version')->assertJsonPath('latest_version', '4.2.0');
    }

    public function test_une_version_publiee_est_annoncee_apres_le_delai_une_seule_fois(): void
    {
        $this->freezeTime();

        $this->signaler('4.1.0', nouveautes: 'Nouvelle caisse avec photos.')->assertStatus(202)->assertJsonPath('statut', 'programmee');
        $this->signaler('4.1.0')->assertOk()->assertJsonPath('statut', 'deja_annoncee');
        $this->signaler('3.9.0')->assertOk()->assertJsonPath('statut', 'deja_annoncee');

        $annonce = Annonce::where('version', '4.1.0')->sole();
        $this->assertSame(now()->addHours(3)->toDateTimeString(), $annonce->programmee_le->toDateTimeString());

        // Avant le délai : rien n'est parti, l'application propose encore la 3.0.1.
        $this->artisan('ecaisse:diffuser-annonces')->assertSuccessful();
        $this->assertSame(0, NotificationApp::count());
        $this->getJson('/api/app-version')->assertJsonPath('latest_version', '3.0.1');

        // Après : les commerçants sont prévenus et l'application propose la 4.1.0.
        $this->travel(3)->hours();
        $this->artisan('ecaisse:diffuser-annonces')->assertSuccessful();
        $this->assertSame(1, NotificationApp::count());
        $this->assertStringContainsString('Version 4.1.0 disponible. Nouvelle caisse avec photos.', NotificationApp::value('message'));
        $this->getJson('/api/app-version')->assertJsonPath('latest_version', '4.1.0');

        // La commande qui lit le .env ne la renvoie pas une seconde fois.
        config(['mobile.latest_version' => '4.1.0']);
        $this->artisan('ecaisse:annoncer-mise-a-jour')->assertSuccessful();
        $this->assertSame(1, Annonce::where('version', '4.1.0')->count());
    }

    public function test_une_version_mal_formee_est_refusee(): void
    {
        $this->signaler('4.1')->assertUnprocessable();
        $this->signaler('v4.1.0')->assertUnprocessable();
    }
}
