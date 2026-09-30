<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Achats\Index;
use App\Models\Boutique;
use App\Models\Fournisseur;
use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AchatsTest extends TestCase
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
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        $this->riz = Produit::create(['nom' => 'Riz', 'prix_vente' => 5000, 'prix_achat' => 4000, 'taux_tva' => 0, 'stock' => 3]);
        $this->caissier = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Moussa', 'phone' => '+22370000002', 'password' => 'password123']);
        $this->caissier->assignRole('caissier');
    }

    private function api(User $u)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($u->createToken('t')->plainTextToken);
    }

    public function test_une_reception_augmente_le_stock_met_a_jour_le_cout_et_la_dette(): void
    {
        $fournisseur = $this->api($this->admin)->postJson('/api/fournisseurs', ['nom' => 'Grossiste Bamako', 'telephone' => '76001122'])->assertCreated()->json('id');

        $this->api($this->admin)->postJson('/api/achats', [
            'fournisseur_id' => $fournisseur, 'reference' => 'BL-128',
            'lignes' => [['produit_id' => $this->riz->id, 'quantite' => 20, 'prix_achat' => 4200]],
            'montant_paye' => 50000, 'moyen_paiement' => 'orange_money',
        ])->assertCreated()->assertJsonPath('total', 84000);

        $riz = $this->riz->fresh();
        $this->assertSame(23, $riz->stock);
        $this->assertSame(4200, $riz->prix_achat);
        $this->assertDatabaseHas('mouvements_stock', ['produit_id' => $riz->id, 'type' => 'entree', 'quantite' => 20, 'motif' => 'Réception BL-128']);

        $this->api($this->admin)->getJson('/api/fournisseurs')->assertJsonPath('data.0.solde_du', 34000);
        $this->api($this->admin)->postJson("/api/fournisseurs/{$fournisseur}/paiements", ['montant' => 40000, 'moyen_paiement' => 'especes'])->assertUnprocessable();
        $this->api($this->admin)->postJson("/api/fournisseurs/{$fournisseur}/paiements", ['montant' => 34000, 'moyen_paiement' => 'wave'])
            ->assertCreated()->assertJsonPath('solde_du', 0);
    }

    public function test_un_fournisseur_se_modifie_et_se_retire_une_fois_regle(): void
    {
        $id = $this->api($this->admin)->postJson('/api/fournisseurs', ['nom' => 'Grosiste', 'telephone' => '76001122'])->json('id');

        $this->api($this->admin)->putJson("/api/fournisseurs/{$id}", ['nom' => 'Grossiste Diallo', 'telephone' => '+22376001133'])
            ->assertOk()->assertJsonPath('nom', 'Grossiste Diallo')->assertJsonPath('telephone', '+22376001133');
        $this->api($this->admin)->putJson("/api/fournisseurs/{$id}", ['nom' => ''])->assertJsonValidationErrors('nom');
        $this->api($this->caissier)->putJson("/api/fournisseurs/{$id}", ['nom' => 'X'])->assertForbidden();
        $this->api($this->caissier)->deleteJson("/api/fournisseurs/{$id}")->assertForbidden();

        // Une dette en cours : il reste.
        $this->api($this->admin)->postJson('/api/achats', [
            'fournisseur_id' => $id, 'lignes' => [['produit_id' => $this->riz->id, 'quantite' => 2, 'prix_achat' => 4000]],
        ])->assertCreated();
        $this->api($this->admin)->deleteJson("/api/fournisseurs/{$id}")
            ->assertUnprocessable()->assertJsonValidationErrors('fournisseur');
        $this->assertStringContainsString('8 000', $this->api($this->admin)->deleteJson("/api/fournisseurs/{$id}")->json('errors.fournisseur.0'));

        // Réglée : il sort de la liste, son achat reste dans l'historique.
        $this->api($this->admin)->postJson("/api/fournisseurs/{$id}/paiements", ['montant' => 8000, 'moyen_paiement' => 'especes'])->assertCreated();
        $this->api($this->admin)->deleteJson("/api/fournisseurs/{$id}")->assertOk();
        $this->api($this->admin)->getJson('/api/fournisseurs')->assertJsonCount(0, 'data');
        $this->api($this->admin)->getJson('/api/achats')->assertJsonCount(1, 'data');
        $this->assertSoftDeleted('fournisseurs', ['id' => $id]);
    }

    public function test_le_fournisseur_d_une_autre_boutique_est_introuvable(): void
    {
        ['user' => $autre] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Autre', 'pays' => 'ML', 'telephone' => '76008299', 'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Bina',
        ]);
        $id = $this->api($this->admin)->postJson('/api/fournisseurs', ['nom' => 'Grossiste'])->json('id');

        $this->api($autre)->putJson("/api/fournisseurs/{$id}", ['nom' => 'Piraté'])->assertNotFound();
        $this->api($autre)->deleteJson("/api/fournisseurs/{$id}")->assertNotFound();
        $this->api($this->admin)->getJson('/api/fournisseurs')->assertJsonPath('data.0.nom', 'Grossiste');
    }

    public function test_regles_et_droits(): void
    {
        // Un reste à payer sans fournisseur : impossible à suivre.
        $this->api($this->admin)->postJson('/api/achats', [
            'lignes' => [['produit_id' => $this->riz->id, 'quantite' => 1, 'prix_achat' => 4000]],
        ])->assertUnprocessable()->assertJsonValidationErrors('fournisseur_id');

        // Payé comptant sans fournisseur : accepté.
        $this->api($this->admin)->postJson('/api/achats', [
            'lignes' => [['produit_id' => $this->riz->id, 'quantite' => 1, 'prix_achat' => 4000]], 'montant_paye' => 4000,
        ])->assertCreated();

        $this->api($this->caissier)->getJson('/api/fournisseurs')->assertForbidden();
        $this->api($this->caissier)->postJson('/api/achats', ['lignes' => []])->assertForbidden();
    }

    public function test_back_office_reception_et_paiement(): void
    {
        $this->actingAs($this->admin);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);

        $c = Livewire::test(Index::class)
            ->call('nouvelleReception')
            ->set('fournisseurOuvert', true)->set('nomFournisseur', 'Grossiste')->call('creerFournisseur')
            ->set('lignes.0.produit_id', $this->riz->id)
            ->assertSet('lignes.0.prix_achat', '4000')
            ->set('lignes.0.quantite', '10')->set('lignes.0.prix_achat', '4100')
            ->set('montantPaye', '1000')
            ->call('enregistrerReception')->assertHasNoErrors()
            ->assertSee('Vous devez 40 000');

        $this->assertSame(13, $this->riz->fresh()->stock);
        $this->assertSame(4100, $this->riz->fresh()->prix_achat);

        $f = Fournisseur::firstOrFail();
        $c->call('ouvrirPaiement', $f->id)->assertSet('paiementMontant', '40000')->call('payer')->assertHasNoErrors();
        $this->assertSame(0, $f->soldeDu());

        $this->actingAs($this->caissier);
        $this->get('/achats')->assertRedirect();
    }
}
