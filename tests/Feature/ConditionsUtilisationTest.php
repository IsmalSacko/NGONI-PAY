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

    protected function setUp(): void
    {
        parent::setUp();
        config(['conditions.exiger' => true]);
    }

    /** En-têtes de l'application 4.9.0 sur un Samsung. */
    private const APPLICATION = ['X-Appareil-Plateforme' => 'android', 'X-Appareil-Modele' => 'Samsung SM-A155F', 'X-App-Version' => '4.9.0'];

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

    public function test_la_preuve_dit_le_modele_du_telephone_et_la_version_de_l_application(): void
    {
        $this->withHeaders(self::APPLICATION)->inscription(['conditions_acceptees' => true])->assertCreated();

        $appareil = DB::table('acceptations_conditions')->value('appareil');
        $this->assertStringStartsWith('Android · Samsung SM-A155F · app 4.9.0', $appareil);
    }

    public function test_le_texte_exact_de_la_version_acceptee_est_archive(): void
    {
        $this->inscription(['conditions_acceptees' => true])->assertCreated();

        $this->get('/conditions/archives/'.ConditionsUtilisation::version().'/conditions')->assertOk()
            ->assertSee('Conditions générales d’utilisation et de vente', false)->assertSee('remboursable sous certaines conditions', false);
        $this->get('/conditions/archives/'.ConditionsUtilisation::version().'/confidentialite')->assertOk()->assertSee('RGPD');
        $this->get('/conditions/archives/2020-01-01/conditions')->assertNotFound();
    }

    public function test_sans_acceptation_l_api_refuse_l_application_recente_et_laisse_l_ancienne(): void
    {
        $jeton = $this->inscription()->json('token');

        $this->withToken($jeton)->withHeaders(self::APPLICATION)->getJson('/api/ventes')
            ->assertForbidden()->assertJsonPath('code', 'CONDITIONS_A_ACCEPTER');
        // Toujours ouverts : lire son compte, accepter.
        $this->withToken($jeton)->withHeaders(self::APPLICATION)->getJson('/api/moi')->assertOk();
        // Une application d'avant 4.9.0 ne sait pas montrer les conditions : elle passe, en attendant la mise à jour obligatoire.
        $this->flushHeaders();
        $this->withToken($jeton)->withHeaders([...self::APPLICATION, 'X-App-Version' => '4.8.0'])->getJson('/api/ventes')->assertOk();

        $this->flushHeaders();
        $this->withToken($jeton)->withHeaders(self::APPLICATION)
            ->postJson('/api/conditions/accepter', ['version' => ConditionsUtilisation::version(), 'conditions_acceptees' => true])->assertOk();
        $this->withToken($jeton)->withHeaders(self::APPLICATION)->getJson('/api/ventes')->assertOk();
    }

    public function test_le_back_office_web_demande_d_accepter_avant_tout(): void
    {
        $this->inscription()->assertCreated();
        $user = User::where('name', 'Awa')->firstOrFail();

        $this->actingAs($user)->get('/tableau-de-bord')->assertRedirect(route('conditions.accepter'));
        $this->actingAs($user)->get('/conditions/accepter')->assertOk()->assertSee('Nos conditions évoluent');
        $this->actingAs($user)->post('/conditions/accepter', [])->assertSessionHasErrors('conditions_acceptees');
        $this->assertSame(0, DB::table('acceptations_conditions')->count());

        $this->actingAs($user)->post('/conditions/accepter', ['conditions_acceptees' => '1'])->assertRedirect();
        $this->assertSame('web', DB::table('acceptations_conditions')->value('source'));
        $this->actingAs($user)->get('/tableau-de-bord')->assertOk();
    }

    public function test_la_mise_a_jour_devient_obligatoire_des_que_la_4_9_0_est_publiee(): void
    {
        config(['mobile.latest_version' => '4.8.0', 'mobile.minimum_version' => '2.0.0']);
        $this->getJson('/api/app-version')->assertJsonPath('minimum_version', '2.0.0');

        config(['mobile.latest_version' => '4.9.0']);
        $this->getJson('/api/app-version')->assertJsonPath('minimum_version', '4.9.0');

        // Un minimum plus haut que la version publiée attend qu'elle le soit.
        config(['mobile.minimum_version' => '5.0.0']);
        $this->getJson('/api/app-version')->assertJsonPath('minimum_version', '4.9.0');
        config(['mobile.latest_version' => '5.0.0']);
        $this->getJson('/api/app-version')->assertJsonPath('minimum_version', '5.0.0');
    }

    public function test_une_version_imposee_attend_d_etre_sur_le_play_store_depuis_trois_jours(): void
    {
        config(['mobile.latest_version' => '4.9.0', 'mobile.minimum_version' => '4.11.2']);
        // Pas encore vue sur le Play Store : personne n'est bloqué.
        $this->getJson('/api/app-version')->assertJsonPath('minimum_version', '4.9.0');

        // Vue et annoncée hier : le Play Store ne la propose pas encore partout.
        $annonce = \App\Models\Annonce::create(['type' => 'mise_a_jour', 'titre' => 'Nouvelle version', 'message' => '…', 'version' => '4.11.2',
            'audience' => 'tous', 'statut' => 'envoyee', 'derniere_diffusion' => now()->subDay()]);
        $this->getJson('/api/app-version')->assertJsonPath('minimum_version', '4.9.0');

        // Trois jours plus tard : obligatoire.
        $annonce->update(['derniere_diffusion' => now()->subDays(3)->subMinute()]);
        $this->getJson('/api/app-version')->assertJsonPath('minimum_version', '4.11.2');
    }

    public function test_la_console_montre_l_acceptation_de_chaque_utilisateur(): void
    {
        $this->withHeaders(self::APPLICATION)->inscription(['conditions_acceptees' => true]);
        $this->flushHeaders();
        $exploitant = User::create(['name' => 'Ismaila', 'phone' => '+22373136789', 'password' => 'password123']);
        $exploitant->forceFill(['est_admin_plateforme' => true])->save();
        app(ConditionsUtilisation::class)->accepter($exploitant, request(), 'web');

        $awa = collect($this->withToken($exploitant->createToken('t')->plainTextToken)->getJson('/api/plateforme/utilisateurs')->assertOk()->json('data'))
            ->firstWhere('nom', 'Awa');
        $this->assertSame(ConditionsUtilisation::version(), $awa['conditions']['version']);
        $this->assertTrue($awa['conditions']['a_jour']);
        $this->assertStringContainsString('Samsung SM-A155F', $awa['conditions']['appareil']);

        $this->actingAs($exploitant)->get('/plateforme/utilisateurs')->assertOk()
            ->assertSee('Conditions acceptées')->assertSee('Samsung SM-A155F');
    }
}
