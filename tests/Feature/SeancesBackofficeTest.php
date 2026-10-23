<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Seances\Index as SeancesIndex;
use App\Models\Boutique;
use App\Models\SessionCaisse;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Services\SessionCaisseService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Les séances de caisse au back-office : ouvertes, fermées, écarts ; fermer une séance oubliée. */
class SeancesBackofficeTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Awa', 'pays' => 'ML', 'telephone' => '76008401', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(AbonnementService::class)->accorder($this->awa, 'pro', now()->addMonth(), $this->awa);
        app(TenantContext::class)->setBoutique($this->boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
    }

    public function test_une_seance_oubliee_se_ferme_depuis_le_back_office_avec_son_ecart(): void
    {
        $s = app(SessionCaisseService::class)->ouvrir($this->awa, 10000);
        $this->actingAs($this->awa);

        $page = Livewire::test(SeancesIndex::class)
            ->assertSee('Caisses ouvertes')->assertSee('Awa')
            ->call('ouvrirFermeture', $s->id)->assertSet('compte', '10000')
            ->set('compte', '9 500')->set('notes', 'Pièce perdue')
            ->call('fermer')->assertHasNoErrors()->assertSet('aFermer', null)
            ->assertSee('Il manquait');

        $s = SessionCaisse::findOrFail($s->id);
        $this->assertSame('fermee', $s->statut);
        $this->assertSame(-500, (int) $s->ecart);
        $page->assertSee('Pièce perdue')->assertSee('Aucune caisse ouverte');
    }

    public function test_le_caissier_n_y_a_pas_acces(): void
    {
        $caissier = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Moussa', 'phone' => '+22370000009', 'password' => 'password123']);
        $caissier->assignRole('caissier');
        $this->actingAs($caissier);
        Livewire::test(SeancesIndex::class)->assertForbidden();
    }
}
