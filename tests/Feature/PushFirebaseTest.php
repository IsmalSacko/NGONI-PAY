<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Annonce;
use App\Models\Appareil;
use App\Models\User;
use App\Services\DiffusionAnnonces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushFirebaseTest extends TestCase
{
    use RefreshDatabase;

    private string $cle;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Fausse clé de compte de service, signée avec une vraie clé RSA.
        $rsa = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($rsa, $prive);
        $this->cle = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($this->cle, json_encode([
            'type' => 'service_account', 'project_id' => 'ngoni-caisse',
            'client_email' => 'push@ngoni-caisse.iam.gserviceaccount.com', 'private_key' => $prive,
        ]));
        config(['services.firebase.credentials' => $this->cle]);
    }

    protected function tearDown(): void
    {
        @unlink($this->cle);
        parent::tearDown();
    }

    public function test_l_application_enregistre_son_appareil(): void
    {
        $awa = User::factory()->create();
        $moussa = User::factory()->create();

        $this->withToken($awa->createToken('t')->plainTextToken)->postJson('/api/appareils', ['jeton' => 'jeton-A'])->assertOk();
        $this->assertDatabaseHas('appareils', ['jeton' => 'jeton-A', 'user_id' => $awa->id]);

        // Même téléphone, autre compte : le jeton change de propriétaire.
        $this->app['auth']->forgetGuards();
        $this->withToken($moussa->createToken('t')->plainTextToken)->postJson('/api/appareils', ['jeton' => 'jeton-A'])->assertOk();
        $this->assertSame(1, Appareil::count());
        $this->assertDatabaseHas('appareils', ['jeton' => 'jeton-A', 'user_id' => $moussa->id]);
    }

    public function test_une_annonce_part_en_push_et_oublie_les_appareils_desinstalles(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'jeton-oauth', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => function (Request $requete) {
                return $requete['message']['token'] === 'perime'
                    ? Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
                    : Http::response(['name' => 'projects/ngoni-caisse/messages/1']);
            },
        ]);

        $awa = User::factory()->create();
        Appareil::create(['user_id' => $awa->id, 'jeton' => 'valide']);
        Appareil::create(['user_id' => $awa->id, 'jeton' => 'perime']);

        $annonce = Annonce::create(['type' => 'mise_a_jour', 'titre' => 'Nouvelle version', 'message' => 'Mettez à jour', 'version' => '3.0.2', 'audience' => 'tous']);
        $r = app(DiffusionAnnonces::class)->diffuser($annonce);

        $this->assertSame(1, $r['pushs']);
        $this->assertDatabaseMissing('appareils', ['jeton' => 'perime']);

        Http::assertSent(fn (Request $q) => str_contains($q->url(), 'fcm.googleapis.com/v1/projects/ngoni-caisse/messages:send')
            && $q->hasHeader('Authorization', 'Bearer jeton-oauth')
            && $q['message']['data']['titre'] === 'Nouvelle version'
            && $q['message']['android']['priority'] === 'high'
            && is_string($q['message']['data']['notification_id']));
    }

    public function test_sans_cle_firebase_rien_ne_part(): void
    {
        config(['services.firebase.credentials' => null]);
        Http::fake();

        $awa = User::factory()->create();
        Appareil::create(['user_id' => $awa->id, 'jeton' => 'valide']);
        $r = app(DiffusionAnnonces::class)->diffuser(Annonce::create(['type' => 'message', 'titre' => 'T', 'message' => 'M', 'audience' => 'tous']));

        $this->assertSame(0, $r['pushs']);
        $this->assertSame(1, $r['notifies']);
        Http::assertNothingSent();
    }
}
