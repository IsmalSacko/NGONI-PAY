<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Abonnement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un essai gratuit par téléphone : se réinscrire depuis le même téléphone, avec
 * un autre numéro, ne redonne plus 7 jours.
 */
class EssaiParAppareilTest extends TestCase
{
    use RefreshDatabase;

    private const TELEPHONE_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const TELEPHONE_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @return array<string, mixed> */
    private function inscrire(string $numero, ?string $empreinte): array
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders($empreinte === null ? [] : ['X-Appareil-Empreinte' => $empreinte])
            ->postJson('/api/inscription', [
                'nom_boutique' => "Boutique {$numero}", 'pays' => 'ML', 'telephone' => $numero,
                'password' => 'password123', 'nom_utilisateur' => 'Faty',
            ])->assertCreated()->json();
    }

    private function essaiEnCours(string $userId): bool
    {
        return Abonnement::where('user_id', $userId)->firstOrFail()->fin->isFuture();
    }

    public function test_un_second_compte_depuis_le_meme_telephone_n_a_pas_d_essai(): void
    {
        $premier = $this->inscrire('76000001', self::TELEPHONE_A);
        $this->assertTrue($premier['essai_offert']);
        $this->assertTrue($this->essaiEnCours($premier['user']['id']));

        $second = $this->inscrire('76000002', self::TELEPHONE_A);
        $this->assertFalse($second['essai_offert'], 'même téléphone, autre numéro : pas de nouvel essai');
        $this->assertFalse($this->essaiEnCours($second['user']['id']));

        // Un autre téléphone, ou une ancienne application sans empreinte : essai normal.
        $this->assertTrue($this->inscrire('76000003', self::TELEPHONE_B)['essai_offert']);
        $this->assertTrue($this->inscrire('76000004', null)['essai_offert']);
    }

    public function test_le_telephone_d_un_proprietaire_inscrit_avant_est_reconnu_a_l_usage(): void
    {
        // Inscrit avec une ancienne application, sans empreinte…
        $ancien = $this->inscrire('76000011', null);
        // …puis l'application à jour l'envoie à chaque requête.
        $this->withToken($ancien['token'])->withHeaders(['X-Appareil-Empreinte' => self::TELEPHONE_A])
            ->getJson('/api/notifications')->assertOk();

        $this->assertFalse($this->inscrire('76000012', self::TELEPHONE_A)['essai_offert']);
    }

    public function test_le_telephone_prete_a_un_employe_ne_prive_personne_d_essai(): void
    {
        $proprietaire = $this->inscrire('76000021', null);
        $employe = User::factory()->create(['boutique_id' => $proprietaire['boutique']['id']]);

        $this->withToken($employe->createToken('t')->plainTextToken)->withHeaders(['X-Appareil-Empreinte' => self::TELEPHONE_A])
            ->getJson('/api/notifications');

        $this->assertTrue($this->inscrire('76000022', self::TELEPHONE_A)['essai_offert']);
    }

    public function test_une_empreinte_mal_formee_est_ignoree(): void
    {
        $this->inscrire('76000031', 'pas-une-empreinte');
        $this->assertTrue($this->inscrire('76000032', 'pas-une-empreinte')['essai_offert']);
    }
}
