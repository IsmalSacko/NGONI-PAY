<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Boutiques\Index as BoutiquesIndex;
use App\Models\Boutique;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Services\NumerotationFactures;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Modèle et numérotation des factures, réglés depuis « Ma boutique » du back-office comme dans l'appli. */
class FactureBackofficeTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Distribution Awa', 'pays' => 'CM', 'telephone' => '690008301', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
    }

    public function test_le_modele_distributeur_et_la_numerotation_se_reglent_depuis_le_back_office(): void
    {
        $this->actingAs($this->awa);
        Livewire::test(BoutiquesIndex::class)
            ->call('ouvrirReglages')->assertSet('reglages.modele_facture', 'standard')
            ->set('reglages.modele_facture', 'distributeur')
            ->set('reglages.boite_postale', 'BP 1234 Douala')
            ->set('reglages.psa_taux', '2,5')
            ->set('reglages.libelle_marchandises', 'Liquide nu')
            ->set('reglages.facture_prefixe', 'da')
            ->set('reglages.facture_annee', false)
            ->set('reglages.facture_prochain_numero', '457')
            ->call('enregistrerReglages')->assertHasNoErrors();

        $b = $this->boutique->fresh();
        $this->assertSame('distributeur', $b->modele_facture);
        $this->assertSame('BP 1234 Douala', $b->boite_postale);
        $this->assertEquals(2.5, (float) $b->psa_taux);
        $this->assertSame('Liquide nu', $b->libelle_marchandises);
        $this->assertSame('DA', $b->facture_prefixe);
        $this->assertFalse((bool) $b->facture_annee);
        $this->assertStringContainsString('457', app(NumerotationFactures::class)->apercu($b));

        // Rouvert : les réglages enregistrés reviennent, avec la prochaine facture.
        Livewire::test(BoutiquesIndex::class)->call('ouvrirReglages')
            ->assertSet('reglages.psa_taux', '2,5')->assertSet('reglages.facture_annee', false)
            ->assertSet('reglages.libelle_marchandises', 'Liquide nu')->assertSee('Prochaine facture');
    }

    public function test_un_prefixe_avec_des_signes_est_refuse(): void
    {
        $this->actingAs($this->awa);
        Livewire::test(BoutiquesIndex::class)->call('ouvrirReglages')
            ->set('reglages.facture_prefixe', 'NC-2')
            ->call('enregistrerReglages')->assertHasErrors('reglages.facture_prefixe');
    }
}
