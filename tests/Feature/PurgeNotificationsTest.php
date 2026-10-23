<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificationApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La cloche ne garde que 7 jours : au-delà, lue ou non, la notification part.
 */
class PurgeNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function notification(User $user, int $joursAvant, bool $lue = false): NotificationApp
    {
        $this->travel(-$joursAvant)->days();
        $n = NotificationApp::create([
            'user_id' => $user->id,
            'type' => 'message',
            'titre' => 'Annonce',
            'message' => 'Bonjour',
            'lue_le' => $lue ? now() : null,
        ]);
        $this->travelBack();

        return $n;
    }

    public function test_les_notifications_de_plus_de_7_jours_sont_effacees_lues_ou_non(): void
    {
        $user = User::factory()->create();
        $vieilleNonLue = $this->notification($user, 8);
        $vieilleLue = $this->notification($user, 30, lue: true);
        $recente = $this->notification($user, 6);

        $this->artisan('ecaisse:purger-notifications')->assertSuccessful();

        $this->assertModelMissing($vieilleNonLue);
        $this->assertModelMissing($vieilleLue);
        $this->assertModelExists($recente);
    }

    public function test_le_menage_passe_chaque_jour(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('ecaisse:purger-notifications')
            ->assertSuccessful();
    }
}
