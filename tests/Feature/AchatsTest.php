<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Achats\Index;
use App\Livewire\Achats\Index as AchatsIndex;
use App\Models\Boutique;
use App\Models\Fournisseur;
use App\Models\Plan;
use App\Models\Produit;
use App\Models\User;
use App\Services\AchatService;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_le_back_office_modifie_et_supprime_un_fournisseur(): void
    {
        $f = Fournisseur::create(['nom' => 'Grosiste', 'telephone' => '76001122']);
        $this->actingAs($this->admin);

        Livewire::test(AchatsIndex::class)
            ->call('modifierFournisseur', $f->id)
            ->assertSet('nomEdite', 'Grosiste')->assertSet('telEdite', '76001122')
            ->set('nomEdite', '')->call('enregistrerFournisseur')->assertHasErrors('nomEdite')
            ->set('nomEdite', 'Grossiste Diallo')->set('telEdite', '+22376001133')->call('enregistrerFournisseur')
            ->assertHasNoErrors()->assertSet('fournisseurEdite', null)
            ->assertSee('Grossiste Diallo');
        $this->assertSame('+22376001133', $f->fresh()->telephone);

        // Une dette : refusé, avec le montant.
        app(AchatService::class)->receptionner($this->admin, [['produit_id' => $this->riz->id, 'quantite' => 2, 'prix_achat' => 4000]], $f->id, null);
        Livewire::test(AchatsIndex::class)
            ->call('supprimerFournisseur', $f->id)
            ->assertHasErrors('fournisseur')->assertSee('Vous lui devez encore');
        $this->assertNotSoftDeleted($f);

        app(AchatService::class)->payer($f->fresh(), $this->admin, 8000, 'especes');
        Livewire::test(AchatsIndex::class)
            ->call('supprimerFournisseur', $f->id)
            ->assertHasNoErrors()->assertDontSee('Grossiste Diallo</span>', false);
        $this->assertSoftDeleted($f);
    }

    public function test_le_caissier_ne_modifie_pas_un_fournisseur_depuis_le_back_office(): void
    {
        $f = Fournisseur::create(['nom' => 'Grossiste']);
        $this->caissier->givePermissionTo('achats.view');
        $this->actingAs($this->caissier);

        Livewire::test(AchatsIndex::class)->call('modifierFournisseur', $f->id)->assertForbidden();
        Livewire::test(AchatsIndex::class)->call('supprimerFournisseur', $f->id)->assertForbidden();
        $this->assertNotSoftDeleted($f);
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

    public function test_l_historique_d_un_article_montre_chaque_mouvement_et_le_bilan(): void
    {
        $this->api($this->admin)->postJson('/api/achats', ['lignes' => [['produit_id' => $this->riz->id, 'quantite' => 10, 'prix_achat' => 4000]], 'reference' => 'BL-1', 'montant_paye' => 40000])->assertCreated();
        $this->api($this->admin)->postJson('/api/ventes', [
            'reference_locale' => (string) Str::uuid(),
            'lignes' => [['produit_id' => $this->riz->id, 'quantite' => 2]], 'moyen_paiement' => 'especes',
        ])->assertCreated();
        $this->api($this->admin)->postJson("/api/produits/{$this->riz->id}/ajuster-stock", ['stock' => 9, 'motif' => 'Casse'])->assertOk();

        $r = $this->api($this->admin)->getJson("/api/produits/{$this->riz->id}/mouvements")->assertOk()
            ->assertJsonPath('data.0.type', 'ajustement')->assertJsonPath('data.0.motif', 'Casse')->assertJsonPath('data.0.auteur', 'Awa')
            ->assertJsonPath('data.1.type', 'sortie')->assertJsonPath('data.2.type', 'entree');
        $this->assertEquals(-2, $r->json('data.0.quantite'));
        $this->assertEquals(9, $r->json('data.0.stock_apres'));
        $this->assertEquals(['entrees' => 10, 'ventes' => 2, 'ajustements_plus' => 0, 'ajustements_moins' => 2], $r->json('bilan'));

        $this->api($this->caissier)->getJson("/api/produits/{$this->riz->id}/mouvements")->assertForbidden();
    }

    public function test_inventaire_guide_compte_en_vendant_et_corrige_l_ecart(): void
    {
        // 3 riz en stock, achetés 4 000 F.
        $inv = $this->api($this->admin)->getJson('/api/inventaires/en-cours')->assertOk()->assertJsonPath('statut', 'en_cours')->json('id');
        $this->assertSame($inv, $this->api($this->admin)->getJson('/api/inventaires/en-cours')->json('id'), 'un seul inventaire en cours');

        // On ne trouve qu'1 riz : il en manque 2, soit 8 000 F.
        $this->api($this->admin)->putJson("/api/inventaires/{$inv}/produits/{$this->riz->id}", ['quantite' => 1])->assertOk()
            ->assertJsonPath('comptes', 1)->assertJsonPath('valeur_manquante', 8000)->assertJsonPath('lignes.0.ecart', -2);

        // Une vente après le comptage (1 riz) : elle reste comptée à la validation.
        $this->api($this->admin)->postJson('/api/ventes', [
            'reference_locale' => (string) Str::uuid(),
            'lignes' => [['produit_id' => $this->riz->id, 'quantite' => 1]], 'moyen_paiement' => 'especes',
        ])->assertCreated();
        $this->assertSame(2, (int) $this->riz->fresh()->stock);

        $this->api($this->caissier)->postJson("/api/inventaires/{$inv}/valider")->assertForbidden();
        $this->api($this->admin)->postJson("/api/inventaires/{$inv}/valider")->assertOk()->assertJsonPath('statut', 'valide')->assertJsonPath('valeur_manquante', 8000);
        // 1 trouvé, 1 vendu depuis : 0.
        $this->assertSame(0, (int) $this->riz->fresh()->stock);
        $this->assertDatabaseHas('mouvements_stock', ['produit_id' => $this->riz->id, 'type' => 'ajustement', 'quantite' => -2, 'motif' => 'Inventaire du '.now()->format('d/m/Y')]);
        $this->api($this->admin)->putJson("/api/inventaires/{$inv}/produits/{$this->riz->id}", ['quantite' => 5])->assertUnprocessable();
        $this->api($this->admin)->getJson('/api/inventaires')->assertJsonPath('data.0.valeur_manquante', 8000)->assertJsonPath('data.0.articles_corriges', 1);
        $this->assertNotSame($inv, $this->api($this->admin)->getJson('/api/inventaires/en-cours')->json('id'), 'le suivant repart à zéro');
    }

    public function test_les_pertes_du_mois_par_motif_en_francs(): void
    {
        $this->riz->update(['prix_achat' => 4000, 'stock' => 10]);
        $this->api($this->admin)->postJson("/api/produits/{$this->riz->id}/ajuster-stock", ['stock' => 8, 'motif' => 'Casse : sacs déchirés'])->assertOk();
        $this->api($this->admin)->postJson("/api/produits/{$this->riz->id}/ajuster-stock", ['stock' => 7, 'motif' => 'Vol'])->assertOk();
        $this->api($this->admin)->postJson("/api/produits/{$this->riz->id}/ajuster-stock", ['stock' => 9, 'motif' => 'Réassort'])->assertOk();
        // Des corrections, pas des pertes : sans motif, ou « Erreur de comptage ».
        $this->api($this->admin)->postJson("/api/produits/{$this->riz->id}/ajuster-stock", ['stock' => 5])->assertOk();
        $this->api($this->admin)->postJson("/api/produits/{$this->riz->id}/ajuster-stock", ['stock' => 4, 'motif' => 'Erreur de comptage'])->assertOk();

        $this->api($this->admin)->getJson('/api/stocks/pertes')->assertOk()
            ->assertJsonPath('total', 12000)
            ->assertJsonPath('motifs.0', ['motif' => 'Casse', 'valeur' => 8000])
            ->assertJsonPath('motifs.1', ['motif' => 'Vol', 'valeur' => 4000]);
        $this->api($this->caissier)->getJson('/api/stocks/pertes')->assertForbidden();
    }

    public function test_inventaire_et_pertes_en_pro_seulement(): void
    {
        $this->admin->abonnement()->update(['plan' => 'basic', 'fin' => now()->addMonth()->toDateString()]);
        $this->api($this->admin)->getJson('/api/inventaires/en-cours')->assertForbidden()->assertJsonPath('code', 'FONCTIONNALITE_NON_INCLUSE');
        $this->api($this->admin)->getJson('/api/stocks/pertes')->assertForbidden();
        // L'historique d'un article reste à tous.
        $this->api($this->admin)->getJson("/api/produits/{$this->riz->id}/mouvements")->assertOk();

        $this->admin->abonnement()->update(['plan' => 'pro']);
        $this->api($this->admin)->getJson('/api/inventaires/en-cours')->assertOk();
        $this->assertStringContainsString('contrôle du stock', (string) Plan::where('code', 'pro')->value('description'));
        $this->assertStringContainsString('séances de caisse', (string) Plan::where('code', 'basic')->value('description'));
    }

    public function test_un_ecart_d_inventaire_corrige_ne_compte_plus_comme_perte(): void
    {
        $this->riz->update(['prix_achat' => 4000, 'stock' => 10]);
        // Inventaire : 5 trouvés au lieu de 10, soit 20 000 F d'écart…
        $this->api($this->admin)->postJson("/api/produits/{$this->riz->id}/ajuster-stock", ['stock' => 5, 'motif' => 'Inventaire du '.now()->format('d/m/Y')])->assertOk();
        $this->api($this->admin)->getJson('/api/stocks/pertes')->assertJsonPath('total', 20000);

        // … mais 3 avaient été mal comptés : la correction rattrape 12 000 F.
        $this->api($this->admin)->postJson("/api/produits/{$this->riz->id}/ajuster-stock", ['stock' => 8, 'motif' => 'Erreur de comptage'])->assertOk();
        $this->api($this->admin)->getJson('/api/stocks/pertes')->assertJsonPath('total', 8000);

        // Un réassort ne rattrape rien ; un nouvel inventaire qui retrouve tout, si.
        $this->api($this->admin)->postJson("/api/produits/{$this->riz->id}/ajuster-stock", ['stock' => 12, 'motif' => 'Réassort'])->assertOk();
        $this->api($this->admin)->getJson('/api/stocks/pertes')->assertJsonPath('total', 8000);
        $this->api($this->admin)->postJson("/api/produits/{$this->riz->id}/ajuster-stock", ['stock' => 14, 'motif' => 'Inventaire du '.now()->format('d/m/Y')])->assertOk();
        $this->api($this->admin)->getJson('/api/stocks/pertes')->assertJsonPath('total', 0)->assertJsonPath('motifs', []);

        // Depuis l'application, remonter le stock sans motif corrige aussi.
        $this->api($this->admin)->postJson("/api/produits/{$this->riz->id}/ajuster-stock", ['stock' => 11, 'motif' => 'Inventaire du '.now()->format('d/m/Y')])->assertOk();
        $this->api($this->admin)->getJson('/api/stocks/pertes')->assertJsonPath('total', 12000);
        $this->api($this->admin)->postJson("/api/produits/{$this->riz->id}/ajuster-stock", ['stock' => 14])->assertOk();
        $this->api($this->admin)->getJson('/api/stocks/pertes')->assertJsonPath('total', 0);
    }
}
