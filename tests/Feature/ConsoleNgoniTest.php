<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CycleFacturation;
use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Le contrat v1 de la Console Ngoni, commune à toutes les applications. */
class ConsoleNgoniTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    private array $cle = ['Authorization' => 'Bearer cle-de-test-console', 'X-Console-Operateur' => 'Ismaila'];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['services.console_ngoni.cle' => 'cle-de-test-console']);
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        User::create(['name' => 'Ismael', 'phone' => '+33605758494', 'password' => 'password123'])
            ->forceFill(['est_admin_plateforme' => true])->save();
    }

    public function test_sans_la_bonne_cle_la_console_est_refusee(): void
    {
        $this->getJson('/api/console/v1/resume')->assertUnauthorized();
        $this->getJson('/api/console/v1/resume', ['Authorization' => 'Bearer autre'])->assertUnauthorized();
    }

    public function test_resume_comptes_et_formules_selon_le_contrat(): void
    {
        $this->getJson('/api/console/v1/resume', $this->cle)->assertOk()
            ->assertJsonPath('application.code', 'ngoni-caisse')
            ->assertJsonPath('comptes', 1)
            ->assertJsonPath('essais', 1);

        $compte = $this->getJson('/api/console/v1/comptes?filtre=essai', $this->cle)->assertOk()->json('data.0');
        $this->assertSame((string) $this->awa->id, $compte['id']);
        $this->assertSame('Pressing Awa', $compte['nom']);

        $pro = collect($this->getJson('/api/console/v1/formules', $this->cle)->assertOk()->json('data'))->firstWhere('code', 'pro');
        $this->assertSame(10000, collect($pro['tarifs'])->firstWhere('mois', 1)['montant']);
    }

    public function test_approuver_une_demande_active_l_abonnement(): void
    {
        $demande = app(AbonnementService::class)->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel);

        $ligne = $this->getJson('/api/console/v1/demandes', $this->cle)->assertOk()->json('data.0');
        $this->assertSame((string) $demande->id, $ligne['id']);
        $this->assertSame(1, $ligne['mois']);

        $this->postJson("/api/console/v1/demandes/{$demande->id}/approuver", [], $this->cle)->assertOk()
            ->assertJsonPath('demande.statut', 'approuvee');
        $this->assertSame('pro', Abonnement::where('user_id', $this->awa->id)->value('plan'));
        // Déjà traitée : refus lisible.
        $this->postJson("/api/console/v1/demandes/{$demande->id}/approuver", [], $this->cle)->assertStatus(422);
    }

    public function test_accorder_sans_echeance_et_regler_un_prix(): void
    {
        $this->postJson("/api/console/v1/comptes/{$this->awa->id}/abonnement", ['formule' => 'pro', 'fin' => null, 'note' => 'Partenaire'], $this->cle)->assertOk();
        $abo = Abonnement::where('user_id', $this->awa->id)->first();
        $this->assertSame('pro', $abo->plan);
        $this->assertNull($abo->fin);

        $pro = collect($this->patchJson('/api/console/v1/formules/pro', ['tarifs' => [['mois' => 1, 'montant' => 12000]]], $this->cle)
            ->assertOk()->json('data'))->firstWhere('code', 'pro');
        $this->assertSame(12000, collect($pro['tarifs'])->firstWhere('mois', 1)['montant']);
    }

    public function test_une_autre_application_enregistre_sa_vente_dans_la_boutique_de_l_exploitant(): void
    {
        config(['ecaisse.boutique_ventes_abonnements' => $this->boutique->id]);
        $vente = [
            'reference' => 'demande-42', 'application' => 'ngoni-sante',
            'client' => ['nom' => 'CSCom de Banconi', 'telephone' => '+22370000001', 'adresse' => 'Ngoni Santé · Centre de santé'],
            'libelle' => 'Abonnement Ngoni Santé · Pro · 3 mois', 'montant' => 42000, 'moyen' => 'orange_money',
        ];

        $id = $this->postJson('/api/console/v1/ventes', $vente, $this->cle)->assertCreated()->json('vente.id');
        // Rejouée, elle ne double pas.
        $this->assertSame($id, $this->postJson('/api/console/v1/ventes', $vente, $this->cle)->assertCreated()->json('vente.id'));
        $this->assertSame(1, \App\Models\Vente::withoutGlobalScopes()->where('boutique_id', $this->boutique->id)->count());
        $this->assertSame(42000, (int) \App\Models\Vente::withoutGlobalScopes()->find($id)->total);
    }
}
