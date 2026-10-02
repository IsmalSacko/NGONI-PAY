<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\ConditionsUtilisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Conditions d'utilisation : pages publiques, case obligatoire à
 * l'inscription, preuve d'acceptation, nouvelle version à accepter.
 */
class ConditionsUtilisationTest extends TestCase
{
    use RefreshDatabase;

    private function inscription(array $en_plus = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/inscription', [
            'nom_boutique' => 'Épicerie Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'password' => 'password123', 'nom_utilisateur' => 'Awa', ...$en_plus,
        ]);
    }

    public function test_les_pages_juridiques_repondent_avec_l_editeur_et_l_offre_a_vie(): void
    {
        $this->get('/conditions')->assertOk()
            ->assertSee('Conditions générales d’utilisation et de vente', false)
            ->assertSee('108 559 998')
            ->assertSee('1er septembre 2026')
            ->assertSee('28 février 2027')
            ->assertSee('tribunaux de Lyon');
        $this->get('/mentions-legales')->assertOk()->assertSee('IsmaelDev')->assertSee('OVH SAS')->assertSee('293 B');
        $this->get('/confidentialite')->assertOk()->assertSee('RGPD')->assertSee('suppression-compte', false)->assertDontSee('conttron');
        // L'adresse donnée au Play Store montre la même page.
        $this->get('/privacy')->assertOk()->assertSee('Politique de confidentialité')->assertDontSee('conttron');
        $this->get('/')->assertOk()->assertSee(route('conditions'), false)->assertSee(route('mentions-legales'), false);
    }

    public function test_inscription_avec_la_case_cochee_garde_la_preuve(): void
    {
        $this->inscription(['conditions_acceptees' => true])->assertCreated();

        $user = User::where('name', 'Awa')->firstOrFail();
        $this->assertSame(ConditionsUtilisation::version(), $user->conditions_version);
        $preuve = DB::table('acceptations_conditions')->where('user_id', $user->id)->first();
        $this->assertSame('inscription', $preuve->source);
        $this->assertSame(ConditionsUtilisation::version(), $preuve->version);
        $this->assertNotNull($preuve->acceptee_le);
        $this->assertNotNull($preuve->ip);
    }

    public function test_inscription_avec_la_case_decochee_est_refusee(): void
    {
        $this->inscription(['conditions_acceptees' => false])->assertUnprocessable()->assertJsonValidationErrors('conditions_acceptees');
        $this->assertSame(0, User::count());
    }

    public function test_une_application_d_avant_la_case_inscrit_et_les_conditions_restent_a_accepter(): void
    {
        $jeton = $this->inscription()->assertCreated()->json('token');

        $this->withToken($jeton)->getJson('/api/moi')->assertOk()
            ->assertJsonPath('conditions.acceptee', false)
            ->assertJsonPath('conditions.version', ConditionsUtilisation::version())
            ->assertJsonPath('conditions.url_conditions', route('conditions'));
    }

    public function test_accepter_la_nouvelle_version_a_la_connexion(): void
    {
        $jeton = $this->inscription()->json('token');

        $this->withToken($jeton)->postJson('/api/conditions/accepter', ['version' => '2020-01-01', 'conditions_acceptees' => true])
            ->assertStatus(409);
        $this->assertSame(0, DB::table('acceptations_conditions')->count(), 'une version dépassée ne vaut pas acceptation');

        $this->withToken($jeton)->postJson('/api/conditions/accepter', ['version' => ConditionsUtilisation::version(), 'conditions_acceptees' => true])
            ->assertOk()->assertJsonPath('conditions.acceptee', true);
        $this->assertSame('connexion', DB::table('acceptations_conditions')->value('source'));
        $this->withToken($jeton)->getJson('/api/moi')->assertJsonPath('conditions.acceptee', true);
    }

    public function test_une_nouvelle_version_redemande_l_acceptation(): void
    {
        $jeton = $this->inscription(['conditions_acceptees' => true])->json('token');
        config(['conditions.version' => '2099-01-01']);

        $this->withToken($jeton)->getJson('/api/moi')->assertJsonPath('conditions.acceptee', false);
    }
}
