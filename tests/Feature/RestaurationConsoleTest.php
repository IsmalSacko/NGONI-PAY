<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Services\ReinitialisationBoutique;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Contrat v1.7 : restaurer depuis la Console Ngoni une boutique remise à zéro. */
class RestaurationConsoleTest extends TestCase
{
    use RefreshDatabase;

    private array $cle = ['Authorization' => 'Bearer cle-de-test-console', 'X-Console-Operateur' => 'Ismaila'];

    public function test_une_remise_a_zero_se_restaure_depuis_la_console_une_seule_fois(): void
    {
        Mail::fake();
        Storage::fake('local');
        config(['services.console_ngoni.cle' => 'cle-de-test-console']);
        User::create(['name' => 'Ismael', 'phone' => '+33605758494', 'password' => 'password123'])->forceFill(['est_admin_plateforme' => true])->save();
        ['user' => $habba, 'boutique' => $boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'DEPENGOU', 'pays' => 'CI', 'telephone' => '0707970437', 'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Habba',
        ]);
        app(TenantContext::class)->setBoutique($boutique->id);
        $avant = Produit::count();
        Produit::factory()->count(3)->create();
        app(TenantContext::class)->forget();

        app(ReinitialisationBoutique::class)->reinitialiser($boutique->id, $habba, false, false);
        $this->assertSame(0, Produit::withoutGlobalScopes()->where('boutique_id', $boutique->id)->count());

        $this->getJson('/api/console/v1/resume', $this->cle)->assertJsonFragment(['restaurer_boutique']);
        $copies = $this->getJson("/api/console/v1/comptes/{$habba->id}", $this->cle)->assertOk()->json('restaurations');
        $this->assertCount(1, $copies);
        $this->assertSame('DEPENGOU', $copies[0]['boutique']);
        $this->assertSame($avant + 3, $copies[0]['articles']);
        $this->assertSame('Habba', $copies[0]['par']);
        $this->assertTrue($copies[0]['plus_recente']);

        $this->postJson("/api/console/v1/comptes/{$habba->id}/restaurer", ['sauvegarde' => $copies[0]['id']], $this->cle)->assertOk()
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, '« DEPENGOU » est restaurée'));
        $this->assertSame($avant + 3, Produit::withoutGlobalScopes()->where('boutique_id', $boutique->id)->count());

        // Une copie ne sert qu'une fois, et ne se restaure pas depuis le compte d'un autre.
        $this->assertSame([], $this->getJson("/api/console/v1/comptes/{$habba->id}", $this->cle)->json('restaurations'));
        $this->postJson("/api/console/v1/comptes/{$habba->id}/restaurer", ['sauvegarde' => $copies[0]['id']], $this->cle)->assertStatus(422)
            ->assertJsonPath('message', 'Copie introuvable : elle a peut-être déjà été restaurée.');
        $this->postJson("/api/console/v1/comptes/{$habba->id}/restaurer", ['sauvegarde' => '../../.env'], $this->cle)->assertStatus(422);
    }
}
