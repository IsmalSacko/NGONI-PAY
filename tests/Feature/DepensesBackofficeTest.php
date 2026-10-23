<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Depenses\Index as DepensesIndex;
use App\Models\Boutique;
use App\Models\Depense;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** La page Dépenses du back-office : comme l'écran Dépenses et la carte Bilan de l'application. */
class DepensesBackofficeTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Awa', 'pays' => 'ML', 'telephone' => '76008301', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(AbonnementService::class)->accorder($this->awa, 'pro', now()->addMonth(), $this->awa);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
    }

    public function test_noter_une_depense_la_voir_au_bilan_puis_la_supprimer(): void
    {
        $this->actingAs($this->awa);
        $page = Livewire::test(DepensesIndex::class)
            ->call('nouvelle')->set('libelle', 'Électricité')->set('categorie', 'energie')->set('montant', '12 500')
            ->call('enregistrer')->assertHasNoErrors()->assertSet('formulaire', false)
            ->assertSee('Électricité')->assertSee('Eau et électricité');
        $depense = Depense::where('libelle', 'Électricité')->firstOrFail();
        $this->assertSame(12500, $depense->montant);
        $this->assertSame($this->awa->id, $depense->user_id);
        // Rien n'est encore entré : le bilan dit ce qui manque.
        $page->assertSee('Il vous manque');

        $page->call('supprimer', $depense->id);
        $this->assertSame(0, Depense::count());
    }

    public function test_une_depense_a_venir_ou_sans_montant_est_refusee(): void
    {
        $this->actingAs($this->awa);
        Livewire::test(DepensesIndex::class)
            ->call('nouvelle')->set('libelle', 'Loyer')->set('montant', '')->set('jour', now()->addDay()->toDateString())
            ->call('enregistrer')->assertHasErrors(['montant', 'jour']);
        $this->assertSame(0, Depense::count());
    }

    public function test_sans_l_offre_la_page_est_fermee(): void
    {
        app(AbonnementService::class)->accorder($this->awa, 'basic', now()->addMonth(), $this->awa);
        $this->actingAs($this->awa)->get('/depenses')->assertForbidden();
        Livewire::test(DepensesIndex::class)->call('nouvelle')->assertForbidden();
    }
}
