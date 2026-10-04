<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Achats\Index as AchatsIndex;
use App\Livewire\Boutiques\Index as BoutiquesIndex;
use App\Livewire\Produits\Index as ProduitsIndex;
use App\Livewire\Stocks\Index as StocksIndex;
use App\Models\Boutique;
use App\Models\Lot;
use App\Models\Plan;
use App\Models\Produit;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Back-office en mode pharmacie : fiche (DCI, ordonnance, détail, premier
 * lot), réception par boîte avec lot, liste des péremptions.
 */
class PharmacieBackofficeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        ['user' => $this->admin, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pharmacie du Fleuve', 'pays' => 'ML', 'telephone' => '+223 76 00 00 00',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        $this->boutique->forceFill(['activite' => 'pharmacie'])->save();
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        Produit::query()->delete();
        $this->actingAs($this->admin);
    }

    public function test_de_la_fiche_au_lot_puis_a_la_liste_des_peremptions(): void
    {
        Livewire::test(ProduitsIndex::class)
            ->call('nouveauProduit')
            ->assertSee('Molécule (DCI)')
            ->set('nom', 'Doliprane 500 mg')
            ->set('dci', 'Paracétamol')
            ->set('sur_ordonnance', false)
            ->set('unite', 'comprime')
            ->set('prix_vente', '100')
            ->set('taux_tva', '0')
            ->call('ajouterPalier')
            ->set('paliers.0.unite', 'boite')
            ->set('paliers.0.contenance', '16')
            ->set('paliers.0.prix', '1400')
            ->set('stock', '32')
            ->set('numero_lot', 'L-1')
            ->set('peremption', now()->addDays(20)->toDateString())
            ->call('enregistrer')
            ->assertHasNoErrors()
            ->assertSet('modaleOuverte', false);

        $p = Produit::where('nom', 'Doliprane 500 mg')->firstOrFail();
        $this->assertSame([['unite' => 'boite', 'contenance' => 16, 'prix' => 1400]], $p->paliers);
        $this->assertSame('Paracétamol', $p->dci);
        $this->assertSame(32, Lot::where('numero', 'L-1')->first()->quantite);

        Livewire::test(AchatsIndex::class)
            ->call('nouvelleReception')
            ->set('lignes.0.produit_id', $p->id)
            ->set('lignes.0.palier', 'boite')
            ->set('lignes.0.quantite', '2')
            ->set('lignes.0.prix_achat', '1000')
            ->set('lignes.0.numero_lot', 'L-2')
            ->set('lignes.0.peremption', now()->addYear()->toDateString())
            ->set('montantPaye', '2000')
            ->call('enregistrerReception')
            ->assertHasNoErrors();
        $this->assertSame(64, $p->fresh()->stock);
        $this->assertSame(32, Lot::where('numero', 'L-2')->first()->quantite);

        Livewire::test(StocksIndex::class)
            ->assertSee('Péremption')
            ->assertSee('64 comprimés (4 boîtes)')
            ->set('filtre', 'peremption')
            ->assertSee('Lot L-1')
            ->assertDontSee('Lot L-2');
    }

    public function test_une_boutique_ne_voit_pas_les_champs_de_pharmacie(): void
    {
        $this->boutique->forceFill(['activite' => 'commerce'])->save();
        Livewire::test(ProduitsIndex::class)
            ->call('nouveauProduit')
            ->assertDontSee('Molécule (DCI)')
            ->assertSee('Vente par lot');
        Livewire::test(StocksIndex::class)->assertDontSee('Péremption');
    }

    public function test_un_refus_se_voit_pres_du_bouton_et_sous_la_ligne_du_carton(): void
    {
        $this->boutique->forceFill(['activite' => 'commerce'])->save();
        $p = Produit::create(['nom' => 'Eau minérale', 'prix_vente' => 400, 'taux_tva' => 0, 'stock' => 34, 'seuil_alerte' => 10]);

        Livewire::test(ProduitsIndex::class)
            ->call('modifier', $p->id)
            ->call('ajouterPalier')
            ->set('paliers.0.contenance', '1')
            ->set('paliers.0.prix', '4500')
            ->call('enregistrer')
            ->assertHasErrors('paliers.0.contenance')
            ->assertDispatched('formulaire-refuse')
            ->assertSet('modaleOuverte', true)
            ->assertSee('Rien n’est enregistré', false)
            ->assertSee('data-erreur', false)
            ->set('paliers.0.contenance', '12')
            ->call('enregistrer')
            ->assertHasNoErrors()
            ->assertSet('modaleOuverte', false);

        $this->assertSame([['unite' => 'carton', 'contenance' => 12, 'prix' => 4500]], $p->fresh()->paliers);
    }

    public function test_le_back_office_ajoute_une_unite_qui_manque_et_la_choisit(): void
    {
        $this->boutique->forceFill(['activite' => 'commerce'])->save();
        Livewire::test(ProduitsIndex::class)
            ->call('nouveauProduit')
            ->set('nouvelleUnite', 'Kilo')
            ->call('ajouterUnite')
            ->assertHasErrors('nouvelleUnite')
            ->set('nouvelleUnite', 'Boule')
            ->call('ajouterUnite')
            ->assertHasNoErrors()
            ->assertSet('unite', 'boule')
            ->assertSee('Vos unités')
            ->set('nom', 'Beurre de karité')
            ->set('prix_vente', '500')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertSame('boule', Produit::where('nom', 'Beurre de karité')->value('unite'));
    }

    public function test_la_page_boutiques_montre_activite_et_fidelite_qui_s_enregistrent_seules(): void
    {
        $this->boutique->forceFill(['activite' => 'commerce'])->save();
        $page = Livewire::test(BoutiquesIndex::class)
            ->assertSee('Activité')
            ->assertSee('Fidélité des clients')
            ->call('choisirActivite', 'pharmacie');
        $this->assertSame('pharmacie', $this->boutique->fresh()->activite);

        // Offre sans fidélité : la carte le dit, rien ne s'enregistre ; avec, l'interrupteur suffit.
        if (app(AbonnementService::class)->permet($this->boutique->fresh(), Plan::FIDELITE)) {
            $page->set('fideliteActive', true)->set('fideliteSeuil', '8')->set('fidelitePct', '12')->assertSet('fideliteEnregistree', true);
            $this->assertSame([8, 12], [(int) $this->boutique->fresh()->fidelite_seuil, (int) $this->boutique->fresh()->fidelite_remise_pct]);
        } else {
            $page->assertSee('Fonction de l’offre Pro', false);
        }
    }
}
