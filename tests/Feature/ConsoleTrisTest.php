<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Plateforme\Comptes;
use App\Livewire\Plateforme\Utilisateurs;
use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\User;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Console : comptes et utilisateurs filtrés, triés, paginés ; abonnements payés par canal. */
class ConsoleTrisTest extends TestCase
{
    use RefreshDatabase;

    private string $jeton;

    /** @var array<string, array{user: User, boutique: Boutique}> */
    private array $c = [];

    protected function setUp(): void
    {
        parent::setUp();
        $inscrire = fn (string $nom, string $tel, string $pays) => app(BoutiqueRegistrationService::class)->register([
            'nom' => "Boutique {$nom}", 'pays' => $pays, 'telephone' => $tel, 'password' => 'password123', 'nom_utilisateur' => $nom,
        ]);

        $admin = $inscrire('Ismael', '73136789', 'ML');
        $admin['user']->forceFill(['est_admin_plateforme' => true])->save();
        $this->jeton = $admin['user']->createToken('app')->plainTextToken;

        $this->c['awa'] = $inscrire('Awa', '76000001', 'ML');       // essai, beaucoup de ventes
        $this->c['moussa'] = $inscrire('Moussa', '770000001', 'SN'); // payant
        $this->c['fanta'] = $inscrire('Fanta', '76000003', 'ML');   // essai expiré, jamais vendu

        foreach (range(1, 5) as $i) {
            Vente::factory()->create(['boutique_id' => $this->c['awa']['boutique']->id, 'user_id' => $this->c['awa']['user']->id, 'total' => 1000]);
        }
        Vente::factory()->create(['boutique_id' => $this->c['moussa']['boutique']->id, 'user_id' => $this->c['moussa']['user']->id, 'total' => 1000]);
        Abonnement::where('user_id', $this->c['moussa']['user']->id)->update(['plan' => 'basic', 'fin' => now()->addMonth()->toDateString()]);
        Abonnement::where('user_id', $this->c['fanta']['user']->id)->update(['fin' => now()->subDay()->toDateString()]);
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->jeton);
    }

    public function test_les_comptes_se_filtrent_et_se_trient(): void
    {
        $noms = fn (array $q) => collect($this->api()->getJson('/api/plateforme/comptes?'.http_build_query($q + ['page' => 1]))->assertOk()->json('data'))->pluck('nom')->all();

        $this->assertSame(['Moussa'], $noms(['statut' => 'payants']));
        $this->assertSame(['Fanta'], $noms(['statut' => 'expires']));
        $this->assertSame(['Moussa'], $noms(['pays' => 'SN']));
        $this->assertContains('Fanta', $noms(['usage' => 'jamais_vendu']));
        $this->assertNotContains('Awa', $noms(['usage' => 'jamais_vendu']));
        $this->assertSame('Awa', $noms(['tri' => 'ventes'])[0], 'le plus de ventes d’abord');

        $page = $this->api()->getJson('/api/plateforme/comptes?page=1')->assertOk();
        $page->assertJsonPath('total', 4)->assertJsonPath('pages', 1)
            ->assertJsonPath('compteurs.payants', 1)->assertJsonPath('compteurs.expires', 1)
            ->assertJsonPath('filtres.pays.SN', 'Sénégal');
        $this->assertSame(5, collect($page->json('data'))->firstWhere('nom', 'Awa')['ventes']);
    }

    public function test_une_ancienne_application_garde_son_filtre(): void
    {
        $this->api()->getJson('/api/plateforme/comptes?filtre=expires')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_les_utilisateurs_se_filtrent_par_role_et_se_trient(): void
    {
        $employe = User::factory()->create(['name' => 'Caissier Awa', 'boutique_id' => $this->c['awa']['boutique']->id]);

        $noms = fn (array $q) => collect($this->api()->getJson('/api/plateforme/utilisateurs?'.http_build_query($q + ['page' => 1]))->assertOk()->json('data'))->pluck('nom')->all();

        $this->assertSame(['Caissier Awa'], $noms(['role' => 'employes']));
        $this->assertSame(['Ismael'], $noms(['role' => 'exploitants']));
        $this->assertNotContains($employe->name, $noms(['role' => 'proprietaires']));
        $this->assertSame('Awa', $noms(['tri' => 'ventes'])[0]);
    }

    public function test_les_abonnements_payes_se_classent_par_canal(): void
    {
        $payer = fn (string $qui, string $moyen, int $montant, bool $auto) => DemandeAbonnement::create([
            'user_id' => $this->c[$qui]['user']->id, 'demande_par' => $this->c[$qui]['user']->id, 'boutique_id' => $this->c[$qui]['boutique']->id,
            'plan' => 'basic', 'cycle' => 'monthly', 'mois' => 1, 'montant' => $montant, 'devise' => 'XOF', 'moyen' => $moyen,
            'frais_mobile' => $auto ? 60 : 0, 'statut' => 'approuvee', 'decide_le' => now(), 'decide_par' => $auto ? null : $this->c['awa']['user']->id,
        ]);
        $payer('awa', 'jeko_wave', 4000, true);
        $payer('moussa', 'pawapay_free_sen', 4000, true);
        $payer('fanta', 'wave', 40000, false);
        $payer('awa', 'orange_money', 4000, false);

        $r = $this->api()->getJson('/api/plateforme/abonnements')->assertOk();
        $r->assertJsonPath('totaux..nombre', 4)
            ->assertJsonPath('totaux.jeko.nombre', 1)
            ->assertJsonPath('totaux.pawapay.nombre', 1)
            ->assertJsonPath('totaux.manuel.nombre', 2)
            ->assertJsonPath('totaux.manuel.montant', 44000);

        $lignes = collect($r->json('data'))->keyBy('moyen');
        $this->assertTrue($lignes['Wave via Jèko']['automatique']);
        $this->assertSame('pawapay', $lignes['Free Money via pawaPay']['canal']);
        $this->assertFalse($lignes['Orange Money']['automatique']);

        $this->api()->getJson('/api/plateforme/abonnements?canal=manuel')->assertJsonPath('total', 2);
    }

    public function test_la_console_web_filtre_trie_et_montre_les_abonnements(): void
    {
        $admin = User::where('est_admin_plateforme', true)->firstOrFail();

        Livewire::actingAs($admin)->test(Comptes::class)
            ->set('filtre', 'payants')->assertSee('Moussa')->assertDontSee('Boutique Fanta')
            ->set('filtre', '')->set('pays', 'SN')->assertSee('Moussa')->assertDontSee('Boutique Awa')
            ->set('pays', '')->set('tri', 'ventes')->assertSeeInOrder(['Boutique Awa', 'Boutique Moussa']);

        Livewire::actingAs($admin)->test(Utilisateurs::class)
            ->set('role', 'exploitants')->assertSee('Ismael')->assertDontSee('Fanta');

        $this->actingAs($admin)->get('/plateforme/abonnements')->assertOk()
            ->assertSee('Abonnements')->assertSee('Validés à la main')->assertSee('pawaPay');
    }
}
