<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CodeReinitialisationMail;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MotDePasseOublieTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->user = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Test',
            'pays' => 'ML',
            'telephone' => '+223 76 00 82 01',
            'email' => 'pressing@example.com',
            'password' => 'ancien-mdp',
            'nom_utilisateur' => 'Awa',
        ])['user'];
    }

    private function demander(string $telephone = '76008201')
    {
        return $this->postJson('/api/mot-de-passe-oublie', ['telephone' => $telephone, 'pays' => 'ML']);
    }

    private function codeEnvoye(): string
    {
        $code = null;
        Mail::assertSent(CodeReinitialisationMail::class, function (CodeReinitialisationMail $mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('pressing@example.com');
        });

        return $code;
    }

    private function reinitialiser(string $code)
    {
        return $this->postJson('/api/reinitialiser-mot-de-passe', [
            'telephone' => '76008201', 'pays' => 'ML', 'code' => $code,
            'password' => 'nouveau-mdp', 'password_confirmation' => 'nouveau-mdp',
        ]);
    }

    public function test_sans_code_le_numero_seul_ne_change_rien(): void
    {
        $this->postJson('/api/reinitialiser-mot-de-passe', [
            'telephone' => '76008201', 'pays' => 'ML',
            'password' => 'pirate-mdp', 'password_confirmation' => 'pirate-mdp',
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertTrue(Hash::check('ancien-mdp', $this->user->fresh()->password));
    }

    public function test_le_code_recu_par_email_change_le_mot_de_passe_et_ferme_les_sessions(): void
    {
        $this->user->createToken('tablette');

        $this->demander()->assertOk()->assertJsonPath('code', 'CODE_DEMANDE');
        $code = $this->codeEnvoye();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertNotSame($code, PasswordResetCode::first()->code_hash);

        $this->reinitialiser($code)->assertOk();

        $this->assertTrue(Hash::check('nouveau-mdp', $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertSame(0, PasswordResetCode::count());

        $this->postJson('/api/connexion', ['telephone' => '76008201', 'pays' => 'ML', 'password' => 'nouveau-mdp'])
            ->assertOk();
    }

    public function test_cinq_mauvais_codes_bloquent_meme_le_bon(): void
    {
        $this->demander();
        $code = $this->codeEnvoye();
        $faux = $code === '111111' ? '222222' : '111111';

        for ($i = 0; $i < 5; $i++) {
            $this->reinitialiser($faux)->assertStatus(422);
        }

        $this->reinitialiser($code)->assertStatus(422);
        $this->assertTrue(Hash::check('ancien-mdp', $this->user->fresh()->password));
    }

    public function test_un_code_expire_est_refuse(): void
    {
        $this->demander();
        $code = $this->codeEnvoye();

        $this->travel(PasswordResetCode::VALIDITY_MINUTES + 1)->minutes();

        $this->reinitialiser($code)->assertStatus(422);
    }

    public function test_meme_reponse_pour_un_numero_inconnu_et_un_compte_sans_email(): void
    {
        $inconnu = $this->demander('70000009')->assertOk()->json();

        app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Sans mail', 'pays' => 'ML', 'telephone' => '70000010',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Moussa',
        ]);
        $sansEmail = $this->demander('70000010')->assertOk()->json();

        $this->assertEquals($inconnu, $sansEmail);
        $this->assertSame('+33605758494', $inconnu['support_whatsapp']);
        Mail::assertNothingSent();
    }

    public function test_les_demandes_en_rafale_sont_limitees(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->demander()->assertOk();
        }

        $this->demander()->assertStatus(429);
    }

    public function test_le_site_propose_le_code_par_email_et_whatsapp(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $user = \App\Models\User::factory()->create(['phone' => '+22376123456', 'email' => 'web@example.com']);

        $this->get('/connexion')->assertSee('Mot de passe oublié ?');
        $this->get('/mot-de-passe-oublie')->assertOk()->assertSee('WhatsApp');

        $code = null;
        $composant = \Livewire\Livewire::test(\App\Livewire\Auth\MotDePasseOublie::class)
            ->set('pays', 'ML')->set('telephone', '76 12 34 56')
            ->call('demander')->assertSet('etape', 'code');

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\CodeReinitialisationMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('web@example.com');
        });

        $composant->set('code', '000000')->set('password', 'nouveau123')->set('password_confirmation', 'nouveau123')
            ->call('reinitialiser')->assertHasErrors('code')
            ->set('code', $code)->call('reinitialiser')->assertHasNoErrors()->assertSet('etape', 'termine');

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('nouveau123', $user->fresh()->password));
    }
}
