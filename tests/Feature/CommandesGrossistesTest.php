<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Client;
use App\Models\Fournisseur;
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
 * Le revendeur commande à son grossiste ; si le grossiste a Ngoni, il confirme
 * et livre depuis son application : vente en gros chez lui, achat chez le
 * revendeur. Sinon la commande part sur WhatsApp et se réceptionne à la main.
 */
class CommandesGrossistesTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private User $grossiste;

    private Boutique $epicerie;

    private Boutique $depot;

    private Produit $riz;

    private Produit $rizDepot;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->grossiste, 'boutique' => $this->depot] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Dépôt Traoré', 'pays' => 'ML', 'telephone' => '76001122', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Traoré',
        ]);
        $this->depot->forceFill(['mode_vente' => 'detail_gros'])->save();
        $this->dans($this->depot);
        $this->rizDepot = Produit::create(['nom' => 'Riz 25 kg', 'code_barre' => '600123', 'prix_vente' => 13500, 'prix_gros' => 12500, 'prix_achat' => 11000, 'taux_tva' => 0, 'stock' => 100]);

        ['user' => $this->awa, 'boutique' => $this->epicerie] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Awa', 'pays' => 'ML', 'telephone' => '76008201', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        $this->dans($this->epicerie);
        $this->riz = Produit::create(['nom' => 'Riz 25kg', 'code_barre' => '600123', 'prix_vente' => 15000, 'prix_achat' => 12000, 'taux_tva' => 0, 'stock' => 2]);
    }

    private function dans(Boutique $boutique): void
    {
        app(TenantContext::class)->setBoutique($boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($boutique->id);
    }

    private function api(User $user)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($user->createToken('t')->plainTextToken);
    }

    public function test_le_grossiste_sur_ngoni_recoit_confirme_et_livre_a_credit(): void
    {
        $fournisseur = $this->api($this->awa)->postJson('/api/fournisseurs', ['nom' => 'Traoré', 'telephone' => '76 00 11 22'])->json('id');
        $this->api($this->awa)->getJson("/api/fournisseurs/{$fournisseur}/ngoni")->assertJsonPath('sur_ngoni', true)->assertJsonPath('boutique', 'Dépôt Traoré');

        $commande = $this->api($this->awa)->postJson('/api/commandes-fournisseur', [
            'fournisseur_id' => $fournisseur, 'lignes' => [['produit_id' => $this->riz->id, 'quantite' => 10]], 'note' => 'Livraison avant samedi',
        ])->assertCreated()->assertJsonPath('statut', 'envoyee')->assertJsonPath('grossiste', 'Dépôt Traoré')
            ->assertJsonPath('whatsapp', null)->assertJsonPath('total_estime', 120000)->json();
        $this->assertDatabaseHas('notifications_app', ['user_id' => $this->grossiste->id, 'titre' => 'Nouvelle commande']);

        // Côté grossiste : l'article est reconnu par son code-barres.
        $recue = $this->api($this->grossiste)->getJson('/api/commandes-recues')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client.nom', 'Épicerie Awa')->assertJsonPath('data.0.lignes.0.produit_grossiste.id', $this->rizDepot->id)->json('data.0');
        $this->api($this->awa)->getJson('/api/commandes-recues')->assertJsonCount(0, 'data');

        $this->api($this->grossiste)->postJson("/api/commandes-recues/{$recue['id']}/livrer")->assertUnprocessable();
        $this->api($this->grossiste)->postJson("/api/commandes-recues/{$recue['id']}/confirmer", ['lignes' => [
            ['id' => $recue['lignes'][0]['id'], 'produit_grossiste_id' => $this->rizDepot->id, 'quantite' => 8],
        ]])->assertOk()->assertJsonPath('statut', 'confirmee')->assertJsonPath('total_confirme', 100000);

        $this->api($this->grossiste)->postJson("/api/commandes-recues/{$recue['id']}/livrer", ['montant_paye' => 40000, 'moyen_paiement' => 'orange_money'])
            ->assertOk()->assertJsonPath('statut', 'livree');

        // Chez le grossiste : une vente au prix de gros, le reste à crédit au client revendeur.
        $vente = Vente::withoutGlobalScopes()->where('boutique_id', $this->depot->id)->sole();
        $this->assertSame(100000, (int) $vente->total);
        $this->assertSame(40000, (int) $vente->montant_paye);
        $client = Client::withoutGlobalScopes()->findOrFail($vente->client_id);
        $this->assertTrue((bool) $client->revendeur);
        $this->assertSame('Épicerie Awa', $client->nom);
        $this->assertSame(92, (int) $this->rizDepot->fresh()->stock);

        // Chez le revendeur : le stock monte, le coût suit, la dette au fournisseur est notée.
        $this->dans($this->epicerie);
        $this->assertSame(10, (int) $this->riz->fresh()->stock);
        $this->assertSame(12500, (int) $this->riz->fresh()->prix_achat);
        $this->api($this->awa)->getJson('/api/fournisseurs')->assertJsonPath('data.0.solde_du', 60000);
        $this->api($this->awa)->getJson('/api/commandes-fournisseur')->assertJsonPath('data.0.statut', 'livree');
        $this->assertDatabaseHas('notifications_app', ['user_id' => $this->awa->id, 'titre' => 'Commande livrée']);
    }

    public function test_sans_ngoni_la_commande_part_sur_whatsapp_et_se_receptionne_a_la_main(): void
    {
        $fournisseur = $this->api($this->awa)->postJson('/api/fournisseurs', ['nom' => 'Kanté', 'telephone' => '+22365000000'])->json('id');

        $commande = $this->api($this->awa)->postJson('/api/commandes-fournisseur', [
            'fournisseur_id' => $fournisseur, 'lignes' => [['produit_id' => $this->riz->id, 'quantite' => 5, 'prix_estime' => 12200]],
        ])->assertCreated()->assertJsonPath('grossiste', null)->json();
        $this->assertStringStartsWith('https://wa.me/22365000000?text=', $commande['whatsapp']);
        $this->assertStringContainsString(rawurlencode('5 × Riz 25kg'), $commande['whatsapp']);

        $this->api($this->awa)->postJson("/api/commandes-fournisseur/{$commande['id']}/receptionner", [
            'lignes' => [['id' => $commande['lignes'][0]['id'], 'quantite' => 4]], 'montant_paye' => 48800,
        ])->assertOk()->assertJsonPath('statut', 'livree')->assertJsonPath('whatsapp', null);

        $this->dans($this->epicerie);
        $this->assertSame(6, (int) $this->riz->fresh()->stock);
        $this->api($this->awa)->getJson('/api/fournisseurs')->assertJsonPath('data.0.solde_du', 0);
        $this->api($this->awa)->postJson("/api/commandes-fournisseur/{$commande['id']}/annuler")->assertUnprocessable();
    }

    public function test_un_grossiste_refuse_et_un_autre_ne_voit_pas_la_commande(): void
    {
        $this->dans($this->epicerie);
        $fournisseur = Fournisseur::create(['nom' => 'Traoré', 'telephone' => '+22376001122']);
        $commande = $this->api($this->awa)->postJson('/api/commandes-fournisseur', [
            'fournisseur_id' => $fournisseur->id, 'lignes' => [['produit_id' => $this->riz->id, 'quantite' => 3]],
        ])->json('id');

        // Une autre boutique (Awa elle-même) ne peut rien faire sur la commande.
        $this->api($this->awa)->postJson("/api/commandes-recues/{$commande}/refuser", ['motif' => 'x'])->assertNotFound();

        $this->api($this->grossiste)->postJson("/api/commandes-recues/{$commande}/refuser", ['motif' => 'Rupture de riz'])
            ->assertOk()->assertJsonPath('statut', 'refusee');
        $this->assertDatabaseHas('notifications_app', ['user_id' => $this->awa->id, 'titre' => 'Commande refusée']);
        $this->api($this->grossiste)->postJson("/api/commandes-recues/{$commande}/confirmer", ['lignes' => []])->assertUnprocessable();
    }

    public function test_changer_le_numero_du_fournisseur_defait_le_lien(): void
    {
        $this->dans($this->epicerie);
        $fournisseur = Fournisseur::create(['nom' => 'Traoré', 'telephone' => '+22376001122']);
        $this->api($this->awa)->getJson("/api/fournisseurs/{$fournisseur->id}/ngoni")->assertJsonPath('sur_ngoni', true);

        $this->api($this->awa)->putJson("/api/fournisseurs/{$fournisseur->id}", ['nom' => 'Traoré', 'telephone' => '+22365999999'])->assertOk();
        $this->api($this->awa)->getJson("/api/fournisseurs/{$fournisseur->id}/ngoni")->assertJsonPath('sur_ngoni', false);
    }
}
