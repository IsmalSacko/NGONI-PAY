<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\MonCompte;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class MonCompteTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private User $exploitant;

    protected function setUp(): void
    {
        parent::setUp();
        ['user' => $this->awa] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        $this->exploitant = User::create(['name' => 'Ismael', 'phone' => '+33605758494', 'password' => 'password123']);
        $this->exploitant->forceFill(['est_admin_plateforme' => true])->save();
    }

    public function test_le_commercant_modifie_son_profil_depuis_le_back_office(): void
    {
        $this->actingAs($this->awa)->get('/mon-compte')->assertOk()->assertSee('Mon compte')->assertSee('Pilotage', false);

        Livewire::actingAs($this->awa)->test(MonCompte::class)
            ->set('name', '  Awa Traoré ')->set('telephone', '76 00 82 99')->set('email', 'awa@exemple.ml')
            ->call('enregistrerProfil')
            ->assertHasNoErrors()->assertSet('telephone', '+22376008299')->assertSee('Profil mis à jour.');

        $this->assertSame(['Awa Traoré', '+22376008299', 'awa@exemple.ml'], [$this->awa->fresh()->name, $this->awa->fresh()->phone, $this->awa->fresh()->email]);
    }

    public function test_l_exploitant_a_sa_page_dans_la_console(): void
    {
        $this->actingAs($this->exploitant)->get('/mon-compte')->assertOk()->assertSee('Console plateforme');

        Livewire::actingAs($this->exploitant)->test(MonCompte::class)
            ->set('name', 'Ismaila Sacko')->call('enregistrerProfil')->assertHasNoErrors();
        $this->assertSame('Ismaila Sacko', $this->exploitant->fresh()->name);
    }

    public function test_un_numero_deja_pris_est_refuse_proprement(): void
    {
        Livewire::actingAs($this->awa)->test(MonCompte::class)
            ->set('telephone', '+33605758494')->call('enregistrerProfil')
            ->assertHasErrors(['telephone'])
            // Corrigé : l'erreur disparaît.
            ->set('telephone', '76008201')->call('enregistrerProfil')
            ->assertHasNoErrors();

        // L'application passe par la même règle : 422, et non plus une erreur serveur.
        $this->withToken($this->awa->createToken('app')->plainTextToken)
            ->putJson('/api/moi', ['name' => 'Awa', 'telephone' => '+33605758494'])
            ->assertUnprocessable()->assertJsonValidationErrors('telephone');
    }

    public function test_changer_de_mot_de_passe_deconnecte_les_appareils(): void
    {
        $this->awa->createToken('tablette');
        $this->awa->createToken('telephone');

        Livewire::actingAs($this->awa)->test(MonCompte::class)
            ->set('mot_de_passe_actuel', 'faux')->set('mot_de_passe', 'nouveau-secret')->set('mot_de_passe_confirmation', 'nouveau-secret')
            ->call('changerMotDePasse')->assertHasErrors(['mot_de_passe_actuel']);
        $this->assertSame(2, $this->awa->tokens()->count(), 'refusé : rien ne change');

        Livewire::actingAs($this->awa)->test(MonCompte::class)
            ->set('mot_de_passe_actuel', 'password123')->set('mot_de_passe', 'court')->set('mot_de_passe_confirmation', 'court')
            ->call('changerMotDePasse')->assertHasErrors(['mot_de_passe']);

        Livewire::actingAs($this->awa)->test(MonCompte::class)
            ->set('mot_de_passe_actuel', 'password123')->set('mot_de_passe', 'nouveau-secret')->set('mot_de_passe_confirmation', 'nouveau-secret')
            ->call('changerMotDePasse')
            ->assertHasNoErrors()->assertSet('mot_de_passe', '')->assertSee('Mot de passe modifié.');

        $this->assertTrue(Hash::check('nouveau-secret', $this->awa->fresh()->password));
        $this->assertSame(0, $this->awa->tokens()->count());
    }

    public function test_reserve_aux_comptes_connectes(): void
    {
        $this->get('/mon-compte')->assertRedirect('/connexion');
    }
}
