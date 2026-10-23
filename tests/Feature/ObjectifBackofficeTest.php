<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Boutique;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** L'objectif du mois au tableau de bord du back-office, comme la carte Élan du Pilotage. */
class ObjectifBackofficeTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Awa', 'pays' => 'ML', 'telephone' => '76008501', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
    }

    public function test_fixer_suivre_puis_retirer_l_objectif(): void
    {
        app(AbonnementService::class)->accorder($this->awa, 'pro', now()->addMonth(), $this->awa);
        $this->actingAs($this->awa);

        $page = Livewire::test(Dashboard::class)->assertSee('Fixer un objectif du mois')
            ->call('ouvrirObjectif')->set('objectif', '1 500 000')->call('enregistrerObjectif')->assertHasNoErrors()
            ->assertSet('objectifOuvert', false)->assertSee('Modifier l’objectif')->assertSee('0 %');
        $this->assertSame(1500000, (int) $this->boutique->fresh()->objectif_mensuel);

        $page->call('ouvrirObjectif')->assertSet('objectif', '1500000')->set('objectif', '')->call('enregistrerObjectif');
        $this->assertNull($this->boutique->fresh()->objectif_mensuel);
    }

    public function test_hors_de_l_offre_ni_carte_ni_reglage(): void
    {
        app(AbonnementService::class)->accorder($this->awa, 'basic', now()->addMonth(), $this->awa);
        $this->actingAs($this->awa);
        Livewire::test(Dashboard::class)->assertDontSee('Objectif du mois')->call('ouvrirObjectif')->assertForbidden();
    }
}
