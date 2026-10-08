<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Annonce;
use App\Models\Appareil;
use App\Models\NotificationApp;
use App\Models\User;
use App\Services\DiffusionAnnonces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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

    private function fcmQuiRepond(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'jeton-oauth', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => fn (Request $q) => str_starts_with($q['message']['token'], 'perime')
                ? Http::response(['error' => ['status' => 'NOT_FOUND']], 404)
                : Http::response(['name' => 'projects/ngoni-caisse/messages/1']),
        ]);
    }

    public function test_une_annonce_a_des_centaines_de_comptes_part_par_lots(): void
    {
        $this->fcmQuiRepond();
        Mail::fake();
        $users = User::factory()->count(260)->create();
        foreach ($users as $i => $user) {
            Appareil::create(['user_id' => $user->id, 'jeton' => ($i % 50 === 0 ? 'perime-' : 'valide-').$i]);
        }

        $annonce = Annonce::create(['type' => 'message', 'titre' => 'Bonjour', 'message' => 'À tous', 'audience' => 'tous', 'statut' => 'programmee', 'programmee_le' => now()]);
        $r = app(DiffusionAnnonces::class)->diffuser($annonce);

        $this->assertSame(['notifies' => 260, 'echecs' => 0, 'pushs' => 254], $r);
        $this->assertSame(260, NotificationApp::where('annonce_id', $annonce->id)->distinct()->count('user_id'));
        $this->assertSame(254, Appareil::count(), 'les appareils désinstallés sont oubliés');
        $this->assertSame(['envoyee', 260], [$annonce->fresh()->statut, $annonce->fresh()->nb_notifies]);
        Http::assertSentCount(1 + 260);
        Mail::assertNothingOutgoing();
    }

    public function test_une_diffusion_interrompue_reprend_sans_doublon(): void
    {
        $this->fcmQuiRepond();
        [$awa, $moussa, $fanta] = User::factory()->count(3)->create()->all();
        $annonce = Annonce::create(['type' => 'message', 'titre' => 'Coupure', 'message' => '…', 'audience' => 'tous',
            'statut' => 'en_cours', 'derniere_diffusion' => now()->subMinutes(5), 'nb_notifies' => 1]);
        // Un tour précédent (la semaine passée) ne compte pas ; Awa est déjà servie dans celui-ci.
        NotificationApp::create(['user_id' => $moussa->id, 'annonce_id' => $annonce->id, 'type' => 'message', 'titre' => 'Coupure', 'message' => '…'])
            ->forceFill(['created_at' => now()->subWeek()])->save();
        NotificationApp::create(['user_id' => $awa->id, 'annonce_id' => $annonce->id, 'type' => 'message', 'titre' => 'Coupure', 'message' => '…']);

        // Une autre diffusion la tient : on n'y touche pas.
        $verrou = Cache::lock("diffusion-annonce-{$annonce->id}", 60);
        $verrou->get();
        $this->artisan('ecaisse:diffuser-annonces')->assertSuccessful();
        $this->assertSame(2, NotificationApp::count());
        $verrou->release();

        // La tâche de chaque minute la reprend.
        $this->artisan('ecaisse:diffuser-annonces')->expectsOutputToContain('1 annonce(s)')->assertSuccessful();

        $this->assertSame(1, NotificationApp::where('user_id', $awa->id)->count(), 'Awa n’est pas renotifiée');
        $this->assertSame(2, NotificationApp::where('user_id', $moussa->id)->count());
        $this->assertSame(1, NotificationApp::where('user_id', $fanta->id)->count());
        $this->assertSame(['envoyee', 3], [$annonce->fresh()->statut, $annonce->fresh()->nb_notifies]);
    }

    public function test_renvoyer_depuis_l_application_relance_un_nouveau_tour(): void
    {
        $this->fcmQuiRepond();
        $exploitant = User::factory()->create(['est_admin_plateforme' => true]);
        $awa = User::factory()->create();
        $annonce = Annonce::create(['type' => 'message', 'titre' => 'Rappel', 'message' => '…', 'audience' => 'tous',
            'statut' => 'envoyee', 'derniere_diffusion' => now()->subDay(), 'nb_notifies' => 1]);
        NotificationApp::create(['user_id' => $awa->id, 'annonce_id' => $annonce->id, 'type' => 'message', 'titre' => 'Rappel', 'message' => '…'])
            ->forceFill(['created_at' => now()->subDay()])->save();

        $this->withToken($exploitant->createToken('t')->plainTextToken)
            ->postJson("/api/plateforme/annonces/{$annonce->id}/envoyer")
            ->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'part vers 1 compte(s)'));

        $this->assertSame(2, NotificationApp::where('user_id', $awa->id)->count());
        $this->assertSame(['envoyee', 2], [$annonce->fresh()->statut, $annonce->fresh()->nb_notifies]);
    }
}
