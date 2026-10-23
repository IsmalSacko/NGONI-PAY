<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Services\Plateforme\Presences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * « En ligne maintenant » ne se dit plus d'une application vue il y a trois
 * minutes : elle se signale toutes les 15 s, annonce son départ, et sans
 * signal depuis 40 s elle n'est plus tenue pour ouverte.
 */
class PresenceTempsReelTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');

        ['user' => $this->awa] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        $this->jeton = $this->awa->createToken('app')->plainTextToken;
    }

    private function presences(): Presences
    {
        return app(Presences::class);
    }

    public function test_le_signal_met_en_ligne_et_le_depart_hors_ligne_aussitot(): void
    {
        $this->withToken($this->jeton)->postJson('/api/presence')->assertNoContent();
        $this->assertSame(1, $this->presences()->nombreEnLigne());
        $recent = $this->presences()->recents()->first();
        $this->assertSame('En ligne maintenant', Presences::depuis($recent->vu_le, $recent->presence_jusqua));

        $this->travel(5)->seconds();
        $this->withToken($this->jeton)->deleteJson('/api/presence')->assertNoContent();

        $this->assertSame(0, $this->presences()->nombreEnLigne());
        $recent = $this->presences()->recents()->first();
        $this->assertFalse($recent->en_ligne);
        $this->assertSame('En ligne il y a moins d’1 min', Presences::depuis($recent->vu_le, $recent->presence_jusqua));
    }

    public function test_sans_signal_depuis_40_s_l_application_n_est_plus_ouverte(): void
    {
        $this->withToken($this->jeton)->postJson('/api/presence')->assertNoContent();

        $this->travel(Presences::PRESENCE_SECONDES - 1)->seconds();
        $this->assertSame(1, $this->presences()->nombreEnLigne(), 'encore dans le délai');

        $this->travel(2)->seconds();
        $this->assertSame(0, $this->presences()->nombreEnLigne(), 'téléphone éteint ou réseau coupé');
    }

    public function test_une_application_sans_signal_est_comptee_a_part_jamais_en_ligne(): void
    {
        DB::table('users')->where('id', $this->awa->id)->update(['vu_le' => now()->subMinutes(2)]);

        $this->assertSame(0, $this->presences()->nombreEnLigne());
        $this->assertSame(1, $this->presences()->nombreActifsSansSignal());
        $this->assertFalse($this->presences()->recents()->first()->en_ligne);
    }

    public function test_le_signal_exige_d_etre_connecte(): void
    {
        $this->postJson('/api/presence')->assertUnauthorized();
        $this->deleteJson('/api/presence')->assertUnauthorized();
    }
}
