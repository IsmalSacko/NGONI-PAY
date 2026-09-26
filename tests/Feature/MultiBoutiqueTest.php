<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Utilisateurs\Index as UtilisateursIndex;
use App\Models\Boutique;
use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Un compte peut gérer plusieurs boutiques. Aucune donnée ne doit passer d'une
 * boutique à l'autre, quelle que soit la façon de la demander.
 */
class MultiBoutiqueTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $pressing;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        ['user' => $this->awa, 'boutique' => $this->pressing] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        $this->jeton = $this->awa->createToken('test')->plainTextToken;

        // Plusieurs boutiques : plan Pro (l'essai n'en couvre qu'une).
        $this->awa->abonnement()->update(['plan' => 'pro', 'fin' => now()->addMonth()->toDateString()]);
        $this->oublierContexte();
    }

    /** Chaque requête HTTP pose son propre contexte ; on part d'une page blanche. */
    private function oublierContexte(): void
    {
        // L'utilisateur d'un actingAs() précédent resterait authentifié, et un
        // jeton envoyé ensuite serait ignoré.
        $this->app['auth']->forgetGuards();
        // withHeaders() garde ses en-têtes pour toutes les requêtes suivantes.
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    private function api(?string $boutique = null)
    {
        $this->oublierContexte();

        return $this->withToken($this->jeton)->withHeaders($boutique ? ['X-Boutique' => $boutique] : []);
    }

    private function creerDeuxiemeBoutique(): string
    {
        return $this->api()->postJson('/api/boutiques', ['nom' => 'Boutique Awa 2', 'pays' => 'CI'])
            ->assertCreated()
            ->json('id');
    }

    public function test_le_proprietaire_cree_une_deuxieme_boutique_et_voit_les_deux(): void
    {
        $this->assertSame($this->awa->id, $this->pressing->proprietaire_id);

        $idB = $this->creerDeuxiemeBoutique();
        $b = Boutique::findOrFail($idB);

        $this->assertSame($this->awa->id, $b->proprietaire_id);
        $this->assertSame('XOF', $b->devise);

        $liste = $this->api()->getJson('/api/boutiques')->assertOk()->json('data');
        $this->assertCount(2, $liste);
        $this->assertEqualsCanonicalizing(['admin', 'admin'], array_column($liste, 'role'));

        // La boutique par défaut n'a pas changé.
        $this->assertSame($this->pressing->id, $this->awa->fresh()->boutique_id);
    }

    public function test_les_donnees_restent_dans_leur_boutique(): void
    {
        $idB = $this->creerDeuxiemeBoutique();

        $this->api($idB)->postJson('/api/produits', ['nom' => 'Pagne', 'prix_vente' => 5000, 'stock' => 3])
            ->assertCreated();

        $this->assertSame(['Pagne'], array_column($this->api($idB)->getJson('/api/produits')->json('data') ?? $this->api($idB)->getJson('/api/produits')->json(), 'nom'));
        $this->assertSame([], array_column($this->api($this->pressing->id)->getJson('/api/produits')->json('data') ?? [], 'nom'));
        $this->assertSame([], array_column($this->api()->getJson('/api/produits')->json('data') ?? [], 'nom'));

        // Un article de B n'est pas atteignable depuis A, même par son identifiant.
        $pagne = Produit::withoutBoutiqueScope()->where('nom', 'Pagne')->firstOrFail();
        $this->api($this->pressing->id)->putJson("/api/produits/{$pagne->id}", ['nom' => 'Piraté'])->assertNotFound();
        $this->assertSame('Pagne', $pagne->fresh()->nom);
    }

    public function test_une_boutique_etrangere_est_refusee(): void
    {
        ['boutique' => $autre] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Chez Moussa', 'pays' => 'ML', 'telephone' => '70000011',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Moussa',
        ]);

        $this->api($autre->id)->getJson('/api/produits')->assertForbidden();
        $this->api($autre->id)->getJson('/api/moi')->assertForbidden();
        $this->api()->putJson("/api/boutiques/{$autre->id}/par-defaut")->assertForbidden();
    }

    public function test_les_ventes_sont_numerotees_par_boutique(): void
    {
        $idB = $this->creerDeuxiemeBoutique();
        $vente = fn () => ['lignes' => [['libelle' => 'Service', 'prix_unitaire' => 1000, 'quantite' => 1]], 'moyen_paiement' => 'especes'];

        $this->api($this->pressing->id)->postJson('/api/ventes', $vente())->assertCreated()->assertJsonPath('numero', 1);
        $this->api($this->pressing->id)->postJson('/api/ventes', $vente())->assertCreated()->assertJsonPath('numero', 2);
        $this->api($idB)->postJson('/api/ventes', $vente())->assertCreated()->assertJsonPath('numero', 1);
    }

    public function test_les_roles_valent_boutique_par_boutique(): void
    {
        // Moussa est propriétaire de sa boutique, et caissier chez Awa.
        ['user' => $moussa] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Chez Moussa', 'pays' => 'ML', 'telephone' => '70000011',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Moussa',
        ]);

        $this->oublierContexte();
        $this->actingAs($this->awa);
        Livewire::test(UtilisateursIndex::class)
            ->call('nouveauCompte')
            ->set('name', 'Moussa')
            ->set('telephone', '70000011')
            ->set('role', 'caissier')
            ->call('enregistrer')
            ->assertSet('info', 'Moussa a été ajouté à l’équipe. Il se connecte avec son mot de passe habituel.');

        $this->assertTrue($moussa->fresh()->appartientA($this->pressing->id));
        $this->assertCount(2, $moussa->fresh()->boutiqueIds());

        $jetonMoussa = $moussa->createToken('test')->plainTextToken;
        $this->oublierContexte();

        // Caissier chez Awa : il encaisse mais ne crée pas d'article.
        $this->withToken($jetonMoussa)->withHeaders(['X-Boutique' => $this->pressing->id])
            ->postJson('/api/produits', ['nom' => 'Intrus', 'prix_vente' => 1])->assertForbidden();
        $this->oublierContexte();
        $this->withToken($jetonMoussa)->withHeaders(['X-Boutique' => $this->pressing->id])
            ->postJson('/api/ventes', ['lignes' => [['libelle' => 'Repassage', 'prix_unitaire' => 500, 'quantite' => 1]], 'moyen_paiement' => 'especes'])
            ->assertCreated();

        // Chez lui, il reste admin.
        $this->oublierContexte();
        $this->withToken($jetonMoussa)->postJson('/api/produits', ['nom' => 'Riz', 'prix_vente' => 500, 'stock' => 1])->assertCreated();

        // On ne peut pas désactiver le compte d'un autre commerçant : seulement le retirer.
        $this->oublierContexte();
        $this->actingAs($this->awa);
        Livewire::test(UtilisateursIndex::class)
            ->call('basculerActivation', $moussa->id)
            ->assertSet('alerte', 'Moussa travaille aussi dans une autre boutique : retirez-le de l’équipe plutôt que de désactiver son compte.');
        $this->assertTrue($moussa->fresh()->is_active);

        Livewire::test(UtilisateursIndex::class)->call('retirer', $moussa->id);
        $this->assertFalse($moussa->fresh()->appartientA($this->pressing->id));
        $this->assertTrue($moussa->fresh()->appartientA($moussa->boutique_id));
    }

    public function test_le_back_office_change_de_boutique(): void
    {
        $idB = $this->creerDeuxiemeBoutique();
        $this->oublierContexte();

        $this->actingAs($this->awa)
            ->post('/boutique-active', ['boutique' => $idB])
            ->assertRedirect(route('tableau-de-bord'));

        $this->assertSame($idB, session('boutique_active'));
        $this->assertSame($idB, $this->awa->fresh()->boutique_id);

        $this->oublierContexte();
        $this->actingAs($this->awa)->get('/tableau-de-bord')->assertOk()->assertSee('Boutique Awa 2');

        $etrangere = (string) Str::uuid();
        $this->actingAs($this->awa)->post('/boutique-active', ['boutique' => $etrangere])->assertForbidden();
    }
}
