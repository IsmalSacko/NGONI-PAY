<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use App\Services\ConditionsUtilisation;
use App\Services\SuppressionCompte;
use Illuminate\Http\Request;
use App\Services\VenteService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SuppressionCompteTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutiqueAwa;

    private User $ibrahim;

    private Boutique $boutiqueIbrahim;

    private User $moussa;

    private User $fanta;

    private User $exploitant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');

        ['user' => $this->awa, 'boutique' => $this->boutiqueAwa] = $this->inscrire('Pressing Awa', '76008201', 'Awa');
        ['user' => $this->ibrahim, 'boutique' => $this->boutiqueIbrahim] = $this->inscrire('Quincaillerie Ibrahim', '76008202', 'Ibrahim');

        // Moussa ne travaille que chez Awa ; Fanta chez Awa (par défaut) et chez Ibrahim.
        $this->moussa = $this->employe('Moussa', '+22370000001', $this->boutiqueAwa);
        $this->fanta = $this->employe('Fanta', '+22370000002', $this->boutiqueAwa);
        $this->dans($this->boutiqueIbrahim);
        $this->fanta->assignRole('caissier');

        $this->vendre($this->boutiqueAwa, $this->moussa);
        $this->vendre($this->boutiqueIbrahim, $this->ibrahim);

        $this->exploitant = User::create(['name' => 'Ismaila', 'phone' => '+22373136789', 'password' => 'password123']);
        $this->exploitant->forceFill(['est_admin_plateforme' => true])->save();
    }

    private function inscrire(string $nom, string $tel, string $qui): array
    {
        return app(BoutiqueRegistrationService::class)->register([
            'nom' => $nom, 'pays' => 'ML', 'telephone' => $tel, 'email' => null, 'password' => 'password123', 'nom_utilisateur' => $qui,
        ]);
    }

    private function dans(Boutique $boutique): void
    {
        app(TenantContext::class)->setBoutique($boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($boutique->id);
    }

    private function employe(string $nom, string $tel, Boutique $boutique): User
    {
        $this->dans($boutique);
        $user = User::create(['boutique_id' => $boutique->id, 'name' => $nom, 'phone' => $tel, 'password' => 'password123']);
        $user->assignRole('caissier');

        return $user;
    }

    private function vendre(Boutique $boutique, User $vendeur): void
    {
        $this->dans($boutique);
        $produit = Produit::first();
        app(VenteService::class)->encaisser([
            'reference_locale' => (string) Str::uuid(),
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'moyen_paiement' => 'especes',
            'montant_recu' => $produit->prix_vente,
            'vendue_hors_ligne' => false,
        ], $vendeur);
    }

    public function test_l_apercu_dit_ce_qui_partira_sans_rien_toucher(): void
    {
        $apercu = app(SuppressionCompte::class)->apercu($this->awa);

        $this->assertSame(['Pressing Awa'], $apercu['boutiques']);
        $this->assertCount(2, $apercu['comptes_supprimes'], 'Awa et Moussa');
        $this->assertSame(['Fanta · +22370000002'], $apercu['comptes_conserves']);
        $this->assertSame(2, $apercu['articles']);
        $this->assertSame(1, $apercu['ventes']);
        $this->assertNull($apercu['blocage']);
        $this->assertNotNull($this->awa->fresh(), 'rien supprimé');
    }

    public function test_le_compte_ses_boutiques_et_ses_employes_disparaissent_les_autres_restent(): void
    {
        $photos = Produit::withoutGlobalScopes()->where('boutique_id', $this->boutiqueAwa->id)->pluck('photo')->all();
        foreach ($photos as $photo) {
            Storage::disk('local')->assertExists($photo);
        }

        $resultat = app(SuppressionCompte::class)->supprimer($this->awa, $this->exploitant);

        // Awa, sa boutique, Moussa et tout le contenu : partis.
        $this->assertNull(User::find($this->awa->id));
        $this->assertNull(User::find($this->moussa->id));
        $this->assertNull(Boutique::withoutGlobalScopes()->find($this->boutiqueAwa->id));
        $this->assertSame(0, DB::table('produits')->where('boutique_id', $this->boutiqueAwa->id)->count());
        $this->assertSame(0, DB::table('ventes')->where('boutique_id', $this->boutiqueAwa->id)->count());
        $this->assertSame(0, DB::table('roles')->where('boutique_id', $this->boutiqueAwa->id)->count());
        $this->assertSame(0, DB::table('abonnements')->where('user_id', $this->awa->id)->count());
        foreach ($photos as $photo) {
            Storage::disk('local')->assertMissing($photo);
        }

        // Fanta reste, rattachée à la boutique d'Ibrahim ; Ibrahim et sa boutique sont intacts.
        $this->assertSame($this->boutiqueIbrahim->id, User::find($this->fanta->id)->boutique_id);
        $this->assertNotNull(User::find($this->ibrahim->id));
        $this->assertSame(1, DB::table('ventes')->where('boutique_id', $this->boutiqueIbrahim->id)->count());
        $this->assertSame(2, DB::table('produits')->where('boutique_id', $this->boutiqueIbrahim->id)->count());

        // La sauvegarde permet de tout restaurer.
        Storage::disk('local')->assertExists($resultat['sauvegarde']);
        $sauvegarde = json_decode(Storage::disk('local')->get($resultat['sauvegarde']), true);
        $this->assertSame('Pressing Awa', $sauvegarde['tables']['boutiques'][0]['nom']);
        $this->assertCount(1, $sauvegarde['tables']['ventes']);
        $this->assertCount(1, $sauvegarde['tables']['lignes_vente']);
        $this->assertCount(2, $sauvegarde['tables']['users']);
    }

    public function test_un_compte_qui_a_accepte_les_conditions_se_supprime_et_la_preuve_reste(): void
    {
        foreach ([$this->awa, $this->moussa] as $u) {
            app(ConditionsUtilisation::class)->accepter($u, Request::create('/', 'POST', server: ['REMOTE_ADDR' => '41.73.1.2']), 'inscription');
        }

        app(SuppressionCompte::class)->supprimer($this->awa, $this->exploitant);

        $this->assertNull(User::find($this->awa->id));
        // La preuve d'acceptation survit au compte : sans lien, mais avec le nom et le numéro.
        $preuve = DB::table('acceptations_conditions')->where('telephone', $this->awa->phone)->first();
        $this->assertNotNull($preuve);
        $this->assertNull($preuve->user_id);
        $this->assertSame($this->awa->name, $preuve->nom);
        $this->assertSame(2, DB::table('acceptations_conditions')->whereNull('user_id')->count());
    }

    public function test_refuse_si_le_compte_a_vendu_dans_la_boutique_d_un_autre(): void
    {
        // Moussa vend aussi chez Ibrahim, sans y avoir de rôle : l'effacer effacerait cette vente.
        $this->vendre($this->boutiqueIbrahim, $this->moussa);

        $this->assertStringContainsString('Quincaillerie Ibrahim', app(SuppressionCompte::class)->apercu($this->awa)['blocage']);
        $this->expectException(ValidationException::class);
        try {
            app(SuppressionCompte::class)->supprimer($this->awa, $this->exploitant);
        } finally {
            $this->assertNotNull(User::find($this->awa->id), 'rien supprimé');
            $this->assertSame(2, Vente::withoutGlobalScopes()->where('boutique_id', $this->boutiqueIbrahim->id)->count());
        }
    }

    public function test_un_administrateur_de_la_plateforme_est_protege(): void
    {
        $this->expectException(ValidationException::class);
        app(SuppressionCompte::class)->supprimer($this->exploitant, $this->exploitant);
    }

    public function test_depuis_l_application_il_faut_confirmer(): void
    {
        $jeton = $this->exploitant->createToken('app')->plainTextToken;

        $this->withToken($jeton)->getJson("/api/plateforme/utilisateurs/{$this->awa->id}/suppression")
            ->assertOk()->assertJsonPath('boutiques.0', 'Pressing Awa')->assertJsonPath('ventes', 1);

        $this->withToken($jeton)->deleteJson("/api/plateforme/utilisateurs/{$this->awa->id}", ['confirmation' => 'oui'])->assertUnprocessable();
        $this->assertNotNull(User::find($this->awa->id));

        $this->withToken($jeton)->deleteJson("/api/plateforme/utilisateurs/{$this->awa->id}", ['confirmation' => 'SUPPRIMER'])
            ->assertOk()->assertJsonPath('boutiques', 1)->assertJsonPath('comptes', 2);
        $this->assertNull(User::find($this->awa->id));
    }

    public function test_depuis_la_console_web_apercu_puis_confirmation(): void
    {
        \Livewire\Livewire::actingAs($this->exploitant)->test(\App\Livewire\Plateforme\Utilisateurs::class)
            ->call('preparerSuppression', $this->awa->id)
            ->assertSee('Pressing Awa')->assertSee('Fanta')
            ->set('confirmation', 'supprimer')->call('supprimer')->assertHasErrors('confirmation')
            ->set('confirmation', 'SUPPRIMER')->call('supprimer')->assertHasNoErrors()
            ->assertSet('aSupprimer', null)->assertSee('supprimé');

        $this->assertNull(User::find($this->awa->id));
    }

    public function test_un_compte_dont_la_boutique_a_ete_effacee_a_la_main_se_supprime_avec_ses_restes(): void
    {
        // Comme en production : la boutique a disparu sans ses données (clés étrangères
        // contournées). Les restes passent sur un identifiant qui n'existe pas ; le
        // contrôle différé des clés n'a jamais lieu, le test étant annulé à la fin.
        $fantome = (string) Str::uuid();
        DB::statement('PRAGMA defer_foreign_keys = ON');
        foreach (['users', 'ventes', 'produits', 'clients', 'categories_produits', 'mouvements_stock', 'sessions_caisse', 'roles', 'model_has_roles'] as $table) {
            DB::table($table)->where('boutique_id', $this->boutiqueAwa->id)->update(['boutique_id' => $fantome]);
        }
        DB::table('boutiques')->where('id', $this->boutiqueAwa->id)->delete();
        $this->boutiqueAwa->id = $fantome;
        $this->assertSame(1, DB::table('ventes')->where('boutique_id', $this->boutiqueAwa->id)->count(), 'restes orphelins');

        $apercu = app(SuppressionCompte::class)->apercu($this->awa->fresh());
        $this->assertNull($apercu['blocage'], 'sa vente est dans sa propre boutique fantôme');
        $this->assertSame(['Boutique déjà supprimée (données restantes)'], $apercu['boutiques']);
        $this->assertSame(1, $apercu['ventes']);

        app(SuppressionCompte::class)->supprimer($this->awa->fresh(), $this->exploitant);

        $this->assertNull(User::find($this->awa->id));
        $this->assertNull(User::find($this->moussa->id));
        foreach (['ventes', 'produits', 'clients', 'categories_produits', 'mouvements_stock', 'sessions_caisse'] as $table) {
            $this->assertSame(0, DB::table($table)->where('boutique_id', $this->boutiqueAwa->id)->count(), "$table nettoyée");
        }
        // Fanta travaillait aussi chez Ibrahim : conservée, rattachée à lui.
        $this->assertSame($this->boutiqueIbrahim->id, User::find($this->fanta->id)->boutique_id);
        $this->assertSame(1, DB::table('ventes')->where('boutique_id', $this->boutiqueIbrahim->id)->count());
    }
}
