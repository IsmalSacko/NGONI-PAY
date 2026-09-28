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
 * Deux jours avant la fin de l'essai, le propriétaire est prévenu — une fois,
 * avec ce qui continue et ce qui s'arrête.
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
        $this->assertStringContainsString('la caisse continue', $rappel->message);
        $this->assertStringContainsString('ne pourrez plus ajouter ni modifier vos articles', $rappel->message);
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

    public function test_ni_un_abonne_ni_un_essai_deja_termine(): void
    {
        $this->finDans(-1);
        $this->artisan('ecaisse:rappeler-fin-essai');

        $this->awa->abonnement()->update(['plan' => 'pro', 'fin' => today()->addDays(2)->toDateString()]);
        $this->artisan('ecaisse:rappeler-fin-essai');

        $this->assertSame(0, $this->rappels()->count());
    }
}
