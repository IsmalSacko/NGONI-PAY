<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Ventes\Index;
use App\Models\Boutique;
use App\Models\Client;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AnnulationCreditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $caissier;

    private Boutique $boutique;

    private Produit $riz;

    protected function setUp(): void
    {
        parent::setUp();
        ['user' => $this->admin, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pharmacie', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        $this->dans();
        $this->riz = Produit::create(['nom' => 'Riz', 'prix_vente' => 5000, 'taux_tva' => 0, 'stock' => 10]);
        $this->caissier = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Caissier', 'phone' => '+22370000002', 'password' => 'password123']);
        $this->caissier->assignRole('caissier');
    }

    private function dans(): void
    {
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
    }

    private function api(User $u)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($u->createToken('t')->plainTextToken);
    }

    private function vendre(User $u, array $extra = [])
    {
        return $this->api($u)->postJson('/api/ventes', $extra + [
            'reference_locale' => (string) Str::uuid(),
            'lignes' => [['produit_id' => $this->riz->id, 'quantite' => 2]],
            'moyen_paiement' => 'especes',
        ]);
    }

    public function test_le_gerant_annule_une_vente_le_stock_revient_et_c_est_trace(): void
    {
        $vente = $this->vendre($this->caissier)->assertCreated()->assertJsonPath('caissier.name', 'Caissier')->json('id');
        $this->assertSame(8, $this->riz->fresh()->stock);

        $this->api($this->caissier)->postJson("/api/ventes/{$vente}/annuler", ['motif' => 'Erreur'])->assertForbidden();
        $this->api($this->admin)->postJson("/api/ventes/{$vente}/annuler")->assertUnprocessable()->assertJsonValidationErrors('motif');

        $this->api($this->admin)->postJson("/api/ventes/{$vente}/annuler", ['motif' => 'Client a changé d’avis'])
            ->assertOk()->assertJsonPath('statut', 'annulee')->assertJsonPath('motif_annulation', 'Client a changé d’avis');
        $this->assertSame(10, $this->riz->fresh()->stock);
        $this->assertDatabaseHas('mouvements_stock', ['vente_id' => $vente, 'type' => 'entree', 'quantite' => 2]);

        $this->api($this->admin)->postJson("/api/ventes/{$vente}/annuler", ['motif' => 'Encore'])->assertUnprocessable();
        $this->api($this->admin)->getJson('/api/dashboard')->assertOk()->assertJsonPath('ventes_jour.total', 0)->assertJsonPath('ventes_jour.nombre', 0);
    }

    public function test_credit_client_dette_reglements_et_annulation(): void
    {
        $this->dans();
        $fatou = Client::create(['nom' => 'Fatou']);

        $this->vendre($this->caissier, ['moyen_paiement' => 'credit_client'])->assertUnprocessable()->assertJsonValidationErrors('client_id');

        $v1 = $this->vendre($this->caissier, ['moyen_paiement' => 'credit_client', 'client_id' => $fatou->id])->assertCreated()->json('id');
        $this->vendre($this->caissier, ['moyen_paiement' => 'credit_client', 'client_id' => $fatou->id])->assertCreated();

        $this->api($this->caissier)->getJson('/api/clients')->assertJsonPath('data.0.solde_du', 20000);

        $this->api($this->caissier)->postJson("/api/clients/{$fatou->id}/reglements", ['montant' => 25000, 'moyen_paiement' => 'especes'])
            ->assertUnprocessable();
        $this->api($this->caissier)->postJson("/api/clients/{$fatou->id}/reglements", ['montant' => 6000, 'moyen_paiement' => 'orange_money'])
            ->assertCreated()->assertJsonPath('solde_du', 14000);

        // Une vente à crédit annulée n'est plus due.
        $this->api($this->admin)->postJson("/api/ventes/{$v1}/annuler", ['motif' => 'Erreur'])->assertOk();
        $this->api($this->caissier)->getJson("/api/clients/{$fatou->id}/credit")
            ->assertOk()->assertJsonPath('solde_du', 4000)->assertJsonCount(1, 'achats')->assertJsonCount(1, 'reglements')
            ->assertJsonPath('reglements.0.par', 'Caissier');

        $this->api($this->caissier)->getJson('/api/clients?debiteurs=1')->assertJsonCount(1, 'data');
    }

    public function test_mentions_du_ticket_et_application_ancienne(): void
    {
        $this->api($this->admin)->putJson('/api/boutique', [
            'nom' => 'Pharmacie', 'pays' => 'ML', 'identifiant_fiscal' => 'NIF 084123456K', 'rccm' => 'MA.BKO.2024.B.1234', 'message_ticket' => 'Merci, à bientôt !',
        ])->assertOk()->assertJsonPath('data.identifiant_fiscal', 'NIF 084123456K');

        // Une application qui n'envoie pas ces champs ne les efface pas.
        $this->api($this->admin)->putJson('/api/boutique', ['nom' => 'Pharmacie', 'pays' => 'ML'])->assertOk()
            ->assertJsonPath('data.rccm', 'MA.BKO.2024.B.1234')->assertJsonPath('data.message_ticket', 'Merci, à bientôt !');
    }

    public function test_back_office_annulation_et_reglement(): void
    {
        $vente = $this->vendre($this->admin)->json('id');
        $this->dans();
        $fatou = Client::create(['nom' => 'Fatou']);
        $this->vendre($this->admin, ['moyen_paiement' => 'credit_client', 'client_id' => $fatou->id])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin);
        $this->dans();

        Livewire::test(Index::class)
            ->call('voir', $vente)->call('annuler')->assertHasErrors('motif')
            ->set('motif', 'Erreur de caisse')->call('annuler')->assertHasNoErrors()
            ->assertSee('Vente annulée')->assertSee('Erreur de caisse');
        $this->assertSame(8, $this->riz->fresh()->stock);

        Livewire::test(\App\Livewire\Clients\Index::class)
            ->assertSee('10 000')
            ->call('ouvrirReglement', $fatou->id)->assertSet('reglementMontant', '10000')
            ->set('reglementMontant', '99999')->call('enregistrerReglement')->assertHasErrors('reglementMontant')
            ->set('reglementMontant', '4000')->call('enregistrerReglement')->assertHasNoErrors();
        $this->assertSame(6000, $fatou->fresh()->soldeDu());
    }

    public function test_une_annulation_ne_touche_jamais_des_chiffres_arretes(): void
    {
        // Journée passée.
        $hier = $this->vendre($this->admin)->json('id');
        Vente::withoutGlobalScopes()->whereKey($hier)->update(['created_at' => now()->subDay(), 'jour_affaire' => now()->subDay()->toDateString()]);
        $this->api($this->admin)->postJson("/api/ventes/{$hier}/annuler", ['motif' => 'x'])
            ->assertUnprocessable()->assertJsonPath('errors.vente.0', 'Seules les ventes de la journée en cours s’annulent : les journées clôturées ou passées sont arrêtées.');

        // Séance fermée.
        $this->api($this->caissier)->postJson('/api/sessions-caisse', ['fond_initial' => 10000])->assertCreated();
        $vente = $this->vendre($this->caissier)->json('id');
        $session = $this->api($this->caissier)->getJson('/api/sessions-caisse/courante')->json('id');
        $this->api($this->caissier)->putJson("/api/sessions-caisse/{$session}/fermer", ['fond_final' => 20000])->assertOk();
        $this->api($this->admin)->postJson("/api/ventes/{$vente}/annuler", ['motif' => 'x'])->assertUnprocessable();

        // Dette déjà en partie remboursée.
        $this->dans();
        $fatou = Client::create(['nom' => 'Fatou']);
        $credit = $this->vendre($this->admin, ['moyen_paiement' => 'credit_client', 'client_id' => $fatou->id])->json('id');
        $this->api($this->admin)->postJson("/api/clients/{$fatou->id}/reglements", ['montant' => 3000, 'moyen_paiement' => 'especes'])->assertCreated();
        $this->api($this->admin)->postJson("/api/ventes/{$credit}/annuler", ['motif' => 'x'])->assertUnprocessable();

        // Aucun de ces refus n'a touché au stock ni aux chiffres.
        $this->assertSame(4, $this->riz->fresh()->stock);
        $this->assertSame(0, Vente::withoutGlobalScopes()->where('statut', 'annulee')->count());
    }
}
