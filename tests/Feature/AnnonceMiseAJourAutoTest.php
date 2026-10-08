<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Annonce;
use App\Models\NotificationApp;
use App\Services\BoutiqueRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AnnonceMiseAJourAutoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        foreach ([['Chez Awa', '76008201', 'awa@example.com'], ['Chez Moussa', '76008202', null]] as [$nom, $tel, $email]) {
            app(BoutiqueRegistrationService::class)->register([
                'nom' => $nom, 'pays' => 'ML', 'telephone' => $tel,
                'email' => $email, 'password' => 'password123', 'nom_utilisateur' => $nom,
            ]);
        }

        // Comme en production : la 3.0.1 a déjà été annoncée depuis la console.
        Annonce::create(['type' => 'mise_a_jour', 'titre' => 'Nouvelle version', 'message' => '…', 'version' => '3.0.1', 'audience' => 'tous', 'statut' => 'envoyee']);
    }

    public function test_une_nouvelle_version_est_annoncee_une_seule_fois_a_tous(): void
    {
        config(['mobile.latest_version' => '3.1.0', 'mobile.nouveautes' => 'Caisse tactile, photos des articles.']);

        $this->artisan('ecaisse:annoncer-mise-a-jour')->assertSuccessful();
        $this->artisan('ecaisse:annoncer-mise-a-jour')->assertSuccessful();

        $annonce = Annonce::where('version', '3.1.0')->sole();
        $this->assertSame('envoyee', $annonce->statut);
        $this->assertSame(2, $annonce->nb_notifies);
        $this->assertSame(2, NotificationApp::where('annonce_id', $annonce->id)->count());
        $this->assertStringContainsString('Version 3.1.0 disponible. Caisse tactile', NotificationApp::where('annonce_id', $annonce->id)->value('message'));
    }

    public function test_rien_n_est_envoye_pour_une_version_deja_annoncee_ou_plus_ancienne(): void
    {
        foreach (['3.0.1', '3.0.0', '2.9.9'] as $version) {
            config(['mobile.latest_version' => $version]);
            $this->artisan('ecaisse:annoncer-mise-a-jour')->assertSuccessful();
        }

        $this->assertSame(1, Annonce::count());
        $this->assertSame(0, NotificationApp::count());
    }

    public function test_l_ordre_des_versions_compte_pas_l_ordre_alphabetique(): void
    {
        // « 3.10.0 » est plus récente que « 3.9.0 », même si elle vient avant à l'alphabet.
        Annonce::create(['type' => 'mise_a_jour', 'titre' => 'x', 'message' => 'x', 'version' => '3.10.0', 'audience' => 'tous', 'statut' => 'envoyee']);
        config(['mobile.latest_version' => '3.9.0']);

        $this->artisan('ecaisse:annoncer-mise-a-jour')->assertSuccessful();

        $this->assertNull(Annonce::where('version', '3.9.0')->first());
    }

    public function test_la_simulation_n_envoie_rien(): void
    {
        config(['mobile.latest_version' => '3.2.0']);
        $this->artisan('ecaisse:annoncer-mise-a-jour', ['--simulation' => true])
            ->expectsOutputToContain('Serait envoyé à 2 compte(s)')
            ->assertSuccessful();
        $this->assertNull(Annonce::where('version', '3.2.0')->first());
    }

    public function test_la_commande_est_planifiee(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('ecaisse:annoncer-mise-a-jour')->assertSuccessful();
    }
}
