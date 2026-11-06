<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\RappelFinEssaiMail;
use App\Models\NotificationApp;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Deux jours avant la fin de l'essai, trois jours avant puis la veille de la
 * fin d'un abonnement payant, le propriétaire est prévenu — une fois par
 * rappel, avec ce qui continue et ce qui s'arrête.
 */
class RappelFinEssaiTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        ['user' => $this->awa] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => 'awa@example.com', 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
    }

    private function finDans(int $jours): void
    {
        $this->awa->abonnement()->update(['fin' => today()->addDays($jours)->toDateString()]);
    }

    private function rappels()
    {
        return NotificationApp::where('user_id', $this->awa->id)->where('type', 'abonnement');
    }

    public function test_rien_tant_que_l_essai_a_plus_de_deux_jours(): void
    {
        $this->finDans(3);
        $this->artisan('ecaisse:rappeler-fin-essai')->assertSuccessful();

        $this->assertSame(0, $this->rappels()->count());
        Mail::assertNotSent(RappelFinEssaiMail::class);
    }

    public function test_deux_jours_avant_le_proprietaire_est_prevenu_une_seule_fois(): void
    {
        $this->finDans(2);

        $this->artisan('ecaisse:rappeler-fin-essai')->assertSuccessful();
        $this->artisan('ecaisse:rappeler-fin-essai')->assertSuccessful();

        $rappel = $this->rappels()->sole();
        $this->assertSame('Votre essai gratuit se termine dans 2 jours', $rappel->titre);
        $this->assertStringContainsString('la caisse ne pourra plus encaisser', $rappel->message);
        $this->assertStringContainsString('restent consultables', $rappel->message);
        $this->assertSame('/abonnement', $rappel->lien);
        Mail::assertSent(RappelFinEssaiMail::class, 1);
        Mail::assertSent(RappelFinEssaiMail::class, fn ($mail) => $mail->hasTo('awa@example.com'));
    }

    public function test_un_passage_manque_se_rattrape_la_veille(): void
    {
        $this->finDans(1);
        $this->artisan('ecaisse:rappeler-fin-essai')->assertSuccessful();

        $this->assertSame('Votre essai gratuit se termine demain', $this->rappels()->sole()->titre);
    }

    public function test_un_essai_prolonge_a_droit_a_un_nouveau_rappel(): void
    {
        $this->finDans(2);
        $this->artisan('ecaisse:rappeler-fin-essai');

        $this->travel(5)->days();
        $this->finDans(2);
        $this->artisan('ecaisse:rappeler-fin-essai');

        $this->assertSame(2, $this->rappels()->count());
    }

    public function test_rien_pour_un_essai_deja_termine_mais_l_abonne_paye_est_prevenu(): void
    {
        $this->finDans(-1);
        $this->artisan('ecaisse:rappeler-fin-essai');
        $this->assertSame(0, $this->rappels()->count());

        // Jusqu'au 06/11/2026, un abonné payant n'était jamais prévenu.
        $this->awa->abonnement()->update(['plan' => 'pro', 'fin' => today()->addDays(2)->toDateString()]);
        $this->artisan('ecaisse:rappeler-fin-essai');
        $this->assertSame(1, $this->rappels()->count());
        $this->assertStringContainsString('Votre abonnement Pro à Ngoni Caisse', $this->rappels()->first()->message);
    }

    /** Awa passe en Basic payé jusqu'à J+$jours. */
    private function abonneeJusqua(int $jours, array $en_plus = []): void
    {
        $this->awa->abonnement()->update(['plan' => 'basic', 'est_actif' => true, 'fin' => today()->addDays($jours)->toDateString(), 'rappel_fin_pour' => null, 'rappel_veille_pour' => null] + $en_plus);
    }

    public function test_abonnement_paye_trois_jours_avant_puis_la_veille_une_fois_chacun(): void
    {
        $this->abonneeJusqua(4);
        $this->artisan('ecaisse:rappeler-fin-essai')->assertSuccessful();
        $this->assertSame(0, $this->rappels()->count(), '4 jours avant : rien encore');

        $this->travel(1)->days();
        $this->artisan('ecaisse:rappeler-fin-essai')->assertSuccessful();
        $this->artisan('ecaisse:rappeler-fin-essai')->assertSuccessful();
        $this->assertSame(1, $this->rappels()->count(), '3 jours avant : un rappel, une seule fois');
        $premier = $this->rappels()->first();
        $this->assertSame('Votre abonnement se termine dans 3 jours', $premier->titre);
        $this->assertStringContainsString('Votre abonnement Basic à Ngoni Caisse se termine le', $premier->message);
        $this->assertStringContainsString('Renouvelez-le dès maintenant (menu Abonnement)', $premier->message);
        $this->assertSame('/abonnement', $premier->lien);
        Mail::assertSent(RappelFinEssaiMail::class, 1);

        $this->travel(1)->days();
        $this->artisan('ecaisse:rappeler-fin-essai')->assertSuccessful();
        $this->assertSame(1, $this->rappels()->count(), '2 jours avant : rien de plus');

        $this->travel(1)->days();
        $this->artisan('ecaisse:rappeler-fin-essai')->assertSuccessful();
        $this->artisan('ecaisse:rappeler-fin-essai')->assertSuccessful();
        $this->assertSame(2, $this->rappels()->count(), 'la veille : le second rappel, une seule fois');
        $this->assertSame('Votre abonnement se termine demain', $this->rappels()->latest('id')->first()->titre);
    }

    public function test_abonnement_decouvert_la_veille_un_seul_rappel(): void
    {
        $this->abonneeJusqua(1);
        $this->artisan('ecaisse:rappeler-fin-essai')->assertSuccessful();
        $this->artisan('ecaisse:rappeler-fin-essai')->assertSuccessful();
        $this->assertSame(1, $this->rappels()->count());
        $this->assertSame('Votre abonnement se termine demain', $this->rappels()->first()->titre);
    }

    public function test_pas_de_rappel_pour_un_abonnement_apple_a_vie_ou_renouvele(): void
    {
        $this->abonneeJusqua(2, ['apple_transaction_originale' => '2000000123456789']);
        $this->artisan('ecaisse:rappeler-fin-essai')->assertSuccessful();
        $this->assertSame(0, $this->rappels()->count(), 'Apple renouvelle seul');

        $this->abonneeJusqua(2, ['apple_transaction_originale' => null]);
        $this->awa->abonnement()->update(['fin' => null]);
        $this->artisan('ecaisse:rappeler-fin-essai')->assertSuccessful();
        $this->assertSame(0, $this->rappels()->count(), 'à vie : pas d’échéance');

        // Renouvelé avant le rappel : nouvelle échéance lointaine, rien.
        $this->abonneeJusqua(40);
        $this->artisan('ecaisse:rappeler-fin-essai')->assertSuccessful();
        $this->assertSame(0, $this->rappels()->count());
    }
}
