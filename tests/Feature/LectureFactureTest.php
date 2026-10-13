<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * « D'après ma facture » : la facture du commerçant lue par Gemini, pour
 * préremplir Ma boutique — rien n'est enregistré.
 */
class LectureFactureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.key' => 'cle-de-test', 'services.gemini.model' => 'modele-a', 'services.gemini.fallback_model' => 'modele-b']);
        ['user' => $this->admin] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Dépôt Boissons', 'pays' => 'ML', 'telephone' => '76008203',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Ismael',
        ]);
    }

    private function envoyer()
    {
        return $this->withToken($this->admin->createToken('t')->plainTextToken)
            ->post('/api/boutique/lecture-facture', ['facture' => UploadedFile::fake()->create('facture.pdf', 120, 'application/pdf')], ['Accept' => 'application/json']);
    }

    private static function reponse(array $lu): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => "```json\n".json_encode($lu)."\n```"]]]]]];
    }

    public function test_la_facture_lue_donne_le_modele_et_les_mentions(): void
    {
        Http::fake(['*modele-a*' => Http::response(self::reponse([
            'modele' => 'distributeur', 'adresse' => 'Akwa, Douala', 'boite_postale' => 'BP 1234',
            'identifiant_fiscal' => 'M0123', 'psa_taux' => '2,5 %', 'tva_taux' => 19.25,
            'message_ticket' => 'Bonnes ventes', 'mentions' => ['Code client', 'Frais d’enlèvement'], 'pirate' => 'x',
        ]))]);

        $this->envoyer()->assertOk()
            ->assertJsonPath('data.modele', 'distributeur')
            ->assertJsonPath('data.boite_postale', 'BP 1234')
            ->assertJsonPath('data.psa_taux', 2.5)
            ->assertJsonPath('data.mentions.1', 'Frais d’enlèvement')
            ->assertJsonMissingPath('data.pirate');
        // Rien n'est enregistré.
        $this->assertNull($this->admin->fresh()->boutique?->boite_postale);
    }

    public function test_un_modele_en_panne_passe_la_main_au_repli(): void
    {
        Http::fake([
            '*modele-a*' => Http::response('', 503),
            '*modele-b*' => Http::response(self::reponse(['modele' => 'standard', 'rccm' => 'RC/DLA'])),
        ]);

        $this->envoyer()->assertOk()->assertJsonPath('data.modele', 'standard')->assertJsonPath('data.rccm', 'RC/DLA');
    }

    public function test_sans_cle_ou_tout_en_panne_un_message_clair(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        $this->envoyer()->assertStatus(503)->assertJsonPath('message', 'La lecture de la facture n’a pas abouti : réessayez dans un instant.');

        config(['services.gemini.key' => null]);
        $this->envoyer()->assertStatus(503);
    }
}
