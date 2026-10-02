<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificationApp;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Supprimer plusieurs notifications d'un coup, ou toutes celles déjà lues. */
class NotificationsEnMasseTest extends TestCase
{
    use RefreshDatabase;

    private function compte(string $tel): User
    {
        return app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique '.$tel, 'pays' => 'ML', 'telephone' => $tel, 'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'X',
        ])['user'];
    }

    private function notifier(User $u, bool $lue = false): int
    {
        return NotificationApp::create(['user_id' => $u->id, 'type' => 'info', 'titre' => 'T', 'message' => 'M', 'lue_le' => $lue ? now() : null])->id;
    }

    public function test_supprime_les_notifications_cochees_et_seulement_les_siennes(): void
    {
        $awa = $this->compte('76008201');
        $ibrahim = $this->compte('76008202');
        [$a, $b, $c] = [$this->notifier($awa), $this->notifier($awa), $this->notifier($awa)];
        $autre = $this->notifier($ibrahim);

        $this->withToken($awa->createToken('t')->plainTextToken)
            ->postJson('/api/notifications/supprimer', ['ids' => [$a, $b, $autre]])
            ->assertOk()->assertJsonPath('supprimees', 2);

        $this->assertDatabaseMissing('notifications_app', ['id' => $a]);
        $this->assertDatabaseMissing('notifications_app', ['id' => $b]);
        $this->assertDatabaseHas('notifications_app', ['id' => $c]);
        // Celle d'un autre compte reste.
        $this->assertDatabaseHas('notifications_app', ['id' => $autre]);
    }

    public function test_supprime_toutes_les_lues_en_gardant_les_non_lues(): void
    {
        $awa = $this->compte('76008201');
        $lue1 = $this->notifier($awa, lue: true);
        $lue2 = $this->notifier($awa, lue: true);
        $nonLue = $this->notifier($awa);

        $this->withToken($awa->createToken('t')->plainTextToken)
            ->postJson('/api/notifications/supprimer', ['lues' => true])
            ->assertOk()->assertJsonPath('supprimees', 2);

        $this->assertDatabaseMissing('notifications_app', ['id' => $lue1]);
        $this->assertDatabaseMissing('notifications_app', ['id' => $lue2]);
        $this->assertDatabaseHas('notifications_app', ['id' => $nonLue]);
    }

    public function test_sans_rien_choisir_la_demande_est_refusee(): void
    {
        $awa = $this->compte('76008201');
        $this->notifier($awa);

        $this->withToken($awa->createToken('t')->plainTextToken)->postJson('/api/notifications/supprimer', [])->assertStatus(422);
        $this->assertSame(1, NotificationApp::where('user_id', $awa->id)->count());
    }
}
