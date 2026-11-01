<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\Client;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les failles de l'audit du 30 octobre : un compte échu faisait tourner son
 * commerce sans fin (caisse sans limite, restaurant et pressing sans stock,
 * changement d'activité, forfait au prix tapé), ou relançait un essai (boutique
 * ouverte par un employé, compte supprimé puis recréé).
 */
class FaillesAbonnementTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
    }

    private function api(?User $user = null, ?string $boutique = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $req = $this->withToken(($user ?? $this->awa)->createToken('t')->plainTextToken);

        return $boutique === null ? $req : $req->withHeader('X-Boutique', $boutique);
    }

    /** Un abonnement Basic a fini il y a [jours] jours (la grâce ne suit pas un essai). */
    private function finiDepuis(int $jours, ?User $u = null): void
    {
        ($u ?? $this->awa)->abonnement()->update([
            'plan' => 'basic',
            'debut' => now()->subDays($jours + 30)->toDateString(),
            'fin' => now()->subDays($jours)->toDateString(),
        ]);
    }

    private function produit(int|float $stock, int $prix = 500): Produit
    {
        app(TenantContext::class)->setBoutique($this->boutique->id);

        return Produit::create(['nom' => 'Riz', 'prix_vente' => $prix, 'taux_tva' => 0, 'stock' => $stock]);
    }

    private function vendre(Produit $p)
    {
        return $this->api()->postJson('/api/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'moyen_paiement' => 'especes']);
    }

    public function test_un_essai_fini_ferme_la_caisse_le_jour_meme(): void
    {
        $riz = $this->produit(9999);
        $this->awa->abonnement()->update(['debut' => now()->subDays(8)->toDateString(), 'fin' => now()->subDay()->toDateString()]);

        $this->vendre($riz)->assertForbidden()->assertJsonPath('code', 'ABONNEMENT_EXPIRE')
            ->assertJsonFragment(['message' => 'Votre essai gratuit est terminé : la caisse ne vend plus. Vos ventes, vos clients et votre stock restent consultables. Abonnez-vous pour continuer.']);
        $this->api()->getJson('/api/abonnement')->assertJsonPath('data.est_en_cours', false);
    }

    public function test_la_caisse_s_arrete_a_16_h_le_dernier_jour_sans_grace(): void
    {
        $riz = $this->produit(9999);
        $this->awa->abonnement()->update(['plan' => 'basic', 'debut' => now()->subMonth()->toDateString(), 'fin' => today()->toDateString()]);

        // Le dernier jour, jusqu'à 16 h : tout marche.
        $this->travelTo(today()->setTime(15, 59));
        $this->vendre($riz)->assertCreated();

        // À 16 h : la caisse ne vend plus, ni le catalogue ni hors catalogue.
        $this->travelTo(today()->setTime(16, 0));
        $this->vendre($riz)->assertForbidden()->assertJsonPath('code', 'ABONNEMENT_EXPIRE')
            ->assertJsonFragment(['message' => 'Votre abonnement a expiré : la caisse ne vend plus. Vos ventes, vos clients et votre stock restent consultables. Abonnez-vous pour continuer.']);
        $this->api()->getJson('/api/ventes')->assertOk();
        $this->api()->getJson('/api/abonnement')->assertJsonPath('data.est_en_cours', false);

        // Ce que les clients doivent s'encaisse toujours.
        app(TenantContext::class)->setBoutique($this->boutique->id);
        $client = Client::create(['nom' => 'Moussa', 'telephone' => '+22370112233', 'solde_du' => 3000]);
        $this->api()->postJson("/api/clients/{$client->id}/reglements", ['montant' => 1000, 'moyen_paiement' => 'especes'])->assertSuccessful();
    }

    public function test_un_restaurant_sans_stock_ne_vend_plus_apres_l_echeance(): void
    {
        $plat = $this->produit(0);
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'restaurant'])->assertOk();
        $commande = fn () => $this->api()->postJson('/api/restaurant/commandes', [
            'type' => 'emporter', 'lignes' => [['produit_id' => $plat->id, 'quantite' => 10]], 'paiement' => ['moyen_paiement' => 'especes'],
        ]);

        $commande()->assertCreated();
        $this->finiDepuis(1);
        $commande()->assertForbidden();
        $this->assertSame(1, Vente::withoutBoutiqueScope()->count());
    }

    public function test_changer_d_activite_ou_vendre_un_forfait_demande_un_abonnement(): void
    {
        app(TenantContext::class)->setBoutique($this->boutique->id);
        $client = Client::create(['nom' => 'Moussa', 'telephone' => '+22370112233'])->id;
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'pressing'])->assertOk();

        $this->finiDepuis(1);
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'restaurant'])->assertForbidden();
        $this->api()->postJson('/api/boutique/demarrage', ['activite' => 'restaurant'])->assertForbidden();
        $this->api()->postJson('/api/pressing/forfaits', [
            'client_id' => $client, 'libelle' => 'N’importe quoi', 'pieces' => 1, 'prix' => 123456,
            'fin' => now()->addYear()->toDateString(), 'moyen_paiement' => 'especes',
        ])->assertForbidden();
        $this->assertSame(0, Vente::withoutBoutiqueScope()->count());
    }

    public function test_le_premier_choix_du_metier_reste_libre_meme_sans_essai(): void
    {
        // Téléphone déjà utilisé : le compte naît avec un essai échu, mais choisit son métier.
        $this->boutique->forceFill(['activite_choisie_le' => null])->save();
        $this->finiDepuis(1);
        $this->api()->postJson('/api/boutique/demarrage', ['activite' => 'commerce'])->assertOk();
    }

    public function test_un_employe_qui_ouvre_une_boutique_n_a_pas_d_essai_neuf(): void
    {
        $this->api(null, $this->boutique->id)->postJson('/api/equipe', [
            'nom' => 'Bakary', 'telephone' => '76008202', 'role' => 'admin', 'password' => 'password123',
        ])->assertCreated();
        $bakary = User::where('phone', 'like', '%76008202')->firstOrFail();
        $this->finiDepuis(4);

        $nouvelle = $this->api($bakary)->postJson('/api/boutiques', ['nom' => 'Boutique Awa 2'])->assertCreated()->json('id');
        $essai = Abonnement::where('user_id', $bakary->id)->firstOrFail();
        $this->assertFalse($essai->estEnCours());

        // Pas de vente dans la nouvelle boutique.
        $this->api($bakary, $nouvelle)->postJson('/api/ventes', [
            'lignes' => [['libelle' => 'Divers', 'prix_unitaire' => 5000, 'quantite' => 1]], 'moyen_paiement' => 'especes',
        ])->assertForbidden();
    }

    public function test_supprimer_son_compte_puis_se_reinscrire_ne_redonne_pas_d_essai(): void
    {
        $inscrire = fn (array $entetes) => $this->withHeaders($entetes)->postJson('/api/inscription', [
            'nom_boutique' => 'Boutique Fanta', 'pays' => 'ML', 'telephone' => '76000011',
            'password' => 'password123', 'nom_utilisateur' => 'Fanta',
        ]);
        $telephone = ['X-Appareil-Empreinte' => str_repeat('a', 64), 'X-Appareil-Plateforme' => 'android'];
        $premier = $inscrire($telephone)->assertCreated()->json();
        $this->assertTrue($premier['essai_offert']);
        $fanta = User::findOrFail($premier['user']['id']);
        $this->api($fanta)->postJson('/api/moi/supprimer', ['password' => 'password123', 'confirmation' => 'SUPPRIMER'])->assertOk();

        // Même numéro, autre appareil (ou sans empreinte) : pas d'essai.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $second = $inscrire(['X-Appareil-Plateforme' => 'windows'])->assertCreated()->json();
        $this->assertFalse($second['essai_offert']);
        $this->assertFalse(Abonnement::where('user_id', $second['user']['id'])->firstOrFail()->estEnCours());
    }

    public function test_le_telephone_d_un_compte_supprime_reste_note(): void
    {
        $telephone = ['X-Appareil-Empreinte' => str_repeat('b', 64), 'X-Appareil-Plateforme' => 'android'];
        $premier = $this->withHeaders($telephone)->postJson('/api/inscription', [
            'nom_boutique' => 'Boutique Fanta', 'pays' => 'ML', 'telephone' => '76000012', 'password' => 'password123', 'nom_utilisateur' => 'Fanta',
        ])->assertCreated()->json();
        $this->api(User::findOrFail($premier['user']['id']))->postJson('/api/moi/supprimer', ['password' => 'password123', 'confirmation' => 'SUPPRIMER'])->assertOk();

        // Autre numéro, même téléphone : pas d'essai.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders($telephone)->postJson('/api/inscription', [
            'nom_boutique' => 'Boutique Fanta 2', 'pays' => 'ML', 'telephone' => '76000013', 'password' => 'password123', 'nom_utilisateur' => 'Fanta',
        ])->assertCreated()->assertJsonPath('essai_offert', false);
    }
}
