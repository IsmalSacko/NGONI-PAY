<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Client;
use App\Models\DemandeAbonnement;
use App\Models\NotificationApp;
use App\Models\User;
use App\Models\Vente;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Contrat v1.4 : ce que les commerçants doivent sur leur abonnement, et leur relance. */
class RelancesCreancesTest extends TestCase
{
    use RefreshDatabase;

    private User $exploitant;

    private Boutique $caisse;

    private User $awa;

    private array $cle = ['Authorization' => 'Bearer cle-de-test-console', 'X-Console-Operateur' => 'Ismaila'];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['services.console_ngoni.cle' => 'cle-de-test-console']);
        $inscrire = fn (string $nom, string $tel, string $qui) => app(BoutiqueRegistrationService::class)->register([
            'nom' => $nom, 'pays' => 'ML', 'telephone' => $tel, 'email' => null, 'password' => 'password123', 'nom_utilisateur' => $qui,
        ]);
        ['user' => $this->exploitant, 'boutique' => $this->caisse] = $inscrire('NGONI CAISSE', '73136789', 'Ismaël');
        $this->exploitant->forceFill(['est_admin_plateforme' => true])->save();
        config(['ecaisse.boutique_ventes_abonnements' => $this->caisse->id]);
        ['user' => $this->awa] = $inscrire('Pressing Awa', '76008201', 'Awa');
        app(AbonnementService::class)->accorder($this->awa, 'pro', now()->addMonth(), $this->exploitant, null);
    }

    /** Une vente à crédit dans la boutique des ventes d'abonnement. */
    private function credit(string $client, ?string $telephone, int $total, int $paye): Client
    {
        app(TenantContext::class)->setBoutique($this->caisse->id);
        $c = Client::where('nom', $client)->first() ?? Client::factory()->create(['nom' => $client, 'telephone' => $telephone]);
        Vente::factory()->create([
            'user_id' => $this->exploitant->id, 'client_id' => $c->id, 'sous_total' => $total, 'tva' => 0, 'total' => $total,
            'montant_paye' => $paye, 'reste_du' => $total - $paye, 'moyen_paiement' => 'especes',
        ]);
        app(TenantContext::class)->forget();

        return $c;
    }

    public function test_la_dette_se_voit_dans_le_resume_le_filtre_et_la_fiche_avec_le_message(): void
    {
        $this->credit('Awa', '+22376008201', 70000, 20000);

        $this->getJson('/api/console/v1/resume', $this->cle)->assertOk()
            ->assertJsonPath('doivent', 1)->assertJsonPath('dettes.0.total', 50000)
            ->assertJsonFragment(['relancer_dette']);

        $ligne = $this->getJson('/api/console/v1/comptes?filtre=doit', $this->cle)->assertOk()->json('data');
        $this->assertCount(1, $ligne);
        $this->assertSame((string) $this->awa->id, $ligne[0]['id']);
        $this->assertSame(50000, $ligne[0]['dette']['montant']);
        $this->assertNull($ligne[0]['dette']['derniere_relance']);
        $debut = now()->format('d/m/Y');
        $this->assertSame(
            "Bonjour Awa, votre abonnement Pro est actif depuis le {$debut} et prend fin le ".now()->addMonth()->format('d/m/Y')
            .'. Sur les 70 000 F de cet abonnement, 20 000 F ont été réglés : il reste 50 000 F à payer. Merci de régulariser auprès de Ngoni Caisse.',
            $ligne[0]['dette']['message'],
        );

        // Les autres comptes n'ont pas de dette.
        $tous = collect($this->getJson('/api/console/v1/comptes', $this->cle)->json('data'));
        $this->assertNull($tous->firstWhere('id', (string) $this->exploitant->id)['dette'] ?? null);
    }

    public function test_relancer_envoie_la_notification_et_note_la_relance(): void
    {
        $this->credit('Awa', '+22376008201', 4000, 0);

        $this->postJson("/api/console/v1/comptes/{$this->awa->id}/relancer", [], $this->cle)->assertOk()
            ->assertJsonPath('message', 'Relance envoyée à Awa (4 000 F).');

        $notif = NotificationApp::where('user_id', $this->awa->id)->where('titre', 'Rappel automatique')->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('Le montant de 4 000 F n’a pas encore été réglé.', $notif->message);
        $this->assertSame(0, NotificationApp::where('user_id', $this->exploitant->id)->where('titre', 'Rappel automatique')->count());
        $this->assertDatabaseHas('relances_creances', ['user_id' => $this->awa->id, 'montant' => 4000, 'automatique' => false, 'par' => 'Console · Ismaila']);

        $fiche = $this->getJson("/api/console/v1/comptes/{$this->awa->id}", $this->cle)->assertOk()->json('compte.dette');
        $this->assertNotNull($fiche['derniere_relance']);
    }

    public function test_une_dette_reglee_ne_se_relance_plus(): void
    {
        $c = $this->credit('Awa', '+22376008201', 4000, 0);
        DB::table('reglements_credit')->insert([
            'id' => (string) str()->uuid(), 'boutique_id' => $this->caisse->id, 'client_id' => $c->id, 'user_id' => $this->exploitant->id,
            'montant' => 4000, 'moyen_paiement' => 'especes', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson('/api/console/v1/resume', $this->cle)->assertJsonPath('doivent', 0);
        $this->postJson("/api/console/v1/comptes/{$this->awa->id}/relancer", [], $this->cle)->assertStatus(422)
            ->assertJsonPath('message', 'Ce compte ne doit rien : pas de relance.');
    }

    public function test_la_fiche_client_se_relie_au_compte_par_le_numero_d_une_demande(): void
    {
        // Le client a donné un autre numéro, noté dans sa demande d'abonnement.
        DemandeAbonnement::create([
            'user_id' => $this->awa->id, 'demande_par' => $this->awa->id, 'plan' => 'pro', 'cycle' => 'monthly', 'mois' => 1,
            'montant' => 10000, 'devise' => 'XOF', 'moyen' => 'wave', 'note' => '+22793494929', 'statut' => 'approuvee',
        ]);
        $this->credit('Abdoul', '+22793494929', 10000, 0);

        $this->assertSame((string) $this->awa->id, $this->getJson('/api/console/v1/comptes?filtre=doit', $this->cle)->json('data.0.id'));
    }

    public function test_la_relance_du_mois_saute_ceux_relances_depuis_moins_de_7_jours(): void
    {
        $this->credit('Awa', '+22376008201', 4000, 0);
        ['user' => $binta] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Binta', 'pays' => 'ML', 'telephone' => '66000001', 'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Binta',
        ]);
        $this->credit('Binta', '+22366000001', 8000, 0);
        $this->postJson("/api/console/v1/comptes/{$this->awa->id}/relancer", [], $this->cle)->assertOk();

        $this->artisan('ecaisse:relancer-creances')->expectsOutput('Relances de paiement : 1 envoyée(s), 1 écartée(s).')->assertSuccessful();
        $this->assertSame(1, NotificationApp::where('user_id', $binta->id)->where('titre', 'Rappel automatique')->count());
        $this->assertSame(1, NotificationApp::where('user_id', $this->awa->id)->where('titre', 'Rappel automatique')->count());
        $this->assertDatabaseHas('relances_creances', ['user_id' => $binta->id, 'automatique' => true]);
    }
}
