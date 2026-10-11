<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Client;
use App\Models\Fournisseur;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\AchatService;
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
        $this->riz = Produit::create(['nom' => 'Riz 25kg', 'code_barre' => '600123', 'prix_vente' => 15000, 'prix_achat' => 12000, 'taux_tva' => 0, 'stock' => 2, 'seuil_alerte' => 5]);
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
        $this->api($this->awa)->getJson('/api/fournisseurs')->assertJsonPath('data.0.grossiste', 'Dépôt Traoré');
        $this->api($this->awa)->getJson("/api/fournisseurs/{$fournisseur}/ngoni")->assertJsonPath('sur_ngoni', true)->assertJsonPath('boutique', 'Dépôt Traoré');
        $this->dans($this->epicerie);
        app(AchatService::class)->receptionner($this->awa, [['produit_id' => $this->riz->id, 'quantite' => 1, 'prix_achat' => 12000]], $fournisseur, null, 12000);
        $this->api($this->awa)->getJson('/api/stocks/a-commander')->assertJsonPath('data.0.fournisseur.grossiste', 'Dépôt Traoré');
        $this->dans($this->epicerie);
        $this->riz->refresh()->update(['stock' => 2]);

        $commande = $this->api($this->awa)->postJson('/api/commandes-fournisseur', [
            'fournisseur_id' => $fournisseur, 'lignes' => [['produit_id' => $this->riz->id, 'quantite' => 10]], 'note' => 'Livraison avant samedi',
        ])->assertCreated()->assertJsonPath('statut', 'envoyee')->assertJsonPath('grossiste', 'Dépôt Traoré')
            ->assertJsonPath('whatsapp', null)->assertJsonPath('total_estime', 120000)->json();
        $this->assertDatabaseHas('notifications_app', ['user_id' => $this->grossiste->id, 'titre' => 'Nouvelle commande']);
        $this->api($this->grossiste)->getJson('/api/moi')->assertJsonPath('commandes_recues', true);
        $this->api($this->awa)->getJson('/api/moi')->assertJsonPath('commandes_recues', false);

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

    public function test_le_revendeur_paie_le_grossiste_confirme_et_le_credit_baisse(): void
    {
        $fournisseur = $this->livrerACredit();

        $this->api($this->awa)->postJson("/api/fournisseurs/{$fournisseur}/paiements", ['montant' => 20000, 'moyen_paiement' => 'orange_money'])->assertCreated();
        $client = Client::withoutGlobalScopes()->where('boutique_id', $this->depot->id)->sole();
        $this->assertDatabaseHas('notifications_app', ['user_id' => $this->grossiste->id, 'titre' => 'Paiement à confirmer', 'lien' => '/clients?client='.$client->id]);

        $paiement = $this->api($this->grossiste)->getJson("/api/clients/{$client->id}/paiements-declares")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.montant', 20000)->assertJsonPath('data.0.boutique', 'Épicerie Awa')->json('data.0.id');
        $this->api($this->awa)->postJson("/api/paiements-declares/{$paiement}/confirmer")->assertNotFound();

        $this->api($this->grossiste)->postJson("/api/paiements-declares/{$paiement}/confirmer")->assertOk()->assertJsonPath('solde_du', 40000);
        $this->api($this->grossiste)->postJson("/api/paiements-declares/{$paiement}/confirmer")->assertUnprocessable();
        $this->assertDatabaseHas('notifications_app', ['user_id' => $this->awa->id, 'titre' => 'Paiement confirmé']);

        // Un second paiement, que le grossiste n'a pas reçu.
        $this->api($this->awa)->postJson("/api/fournisseurs/{$fournisseur}/paiements", ['montant' => 5000, 'moyen_paiement' => 'especes'])->assertCreated();
        $autre = $this->api($this->grossiste)->getJson("/api/clients/{$client->id}/paiements-declares")->json('data.0.id');
        $this->api($this->grossiste)->postJson("/api/paiements-declares/{$autre}/refuser")->assertOk();
        $this->assertDatabaseHas('notifications_app', ['user_id' => $this->awa->id, 'titre' => 'Paiement non reçu']);
        $this->api($this->grossiste)->getJson("/api/clients/{$client->id}/credit")->assertOk();
        $this->assertSame(40000, $client->fresh()->soldeDu());
    }

    public function test_le_revendeur_commande_en_sachets_le_grossiste_livre_en_cartons_et_s_en_souvient(): void
    {
        $this->dans($this->depot);
        $carton = Produit::create(['nom' => 'Faniko carton', 'prix_vente' => 3600, 'taux_tva' => 0, 'stock' => 50]);
        $this->dans($this->epicerie);
        $sachet = Produit::create(['nom' => 'Faniko', 'unite' => null, 'prix_vente' => 300, 'prix_achat' => 120, 'taux_tva' => 0, 'stock' => 0]);
        $fournisseur = Fournisseur::create(['nom' => 'Traoré', 'telephone' => '+22376001122'])->id;

        $commande = $this->api($this->awa)->postJson('/api/commandes-fournisseur', ['fournisseur_id' => $fournisseur, 'lignes' => [['produit_id' => $sachet->id, 'quantite' => 30]]])->json();
        $ligne = $commande['lignes'][0]['id'];
        // 30 sachets, 24 par carton : 2 cartons proposés.
        $this->api($this->grossiste)->postJson("/api/commandes-recues/{$commande['id']}/confirmer", ['lignes' => [
            ['id' => $ligne, 'produit_grossiste_id' => $carton->id, 'contenance' => 24],
        ]])->assertOk()->assertJsonPath('lignes.0.quantite_confirmee', 2)->assertJsonPath('total_confirme', 7200);
        $this->api($this->grossiste)->postJson("/api/commandes-recues/{$commande['id']}/livrer", ['montant_paye' => 7200])->assertOk();

        $this->assertSame(48, (int) $sachet->fresh()->stock);
        $this->assertSame(150, (int) $sachet->fresh()->prix_achat);
        $this->assertSame(48, (int) $carton->fresh()->stock);

        // La fois suivante : son carton et « 24 » déjà là.
        $this->api($this->awa)->postJson('/api/commandes-fournisseur', ['fournisseur_id' => $fournisseur, 'lignes' => [['produit_id' => $sachet->id, 'quantite' => 50]]]);
        $this->api($this->grossiste)->getJson('/api/commandes-recues?statut=envoyee')
            ->assertJsonPath('data.0.lignes.0.produit_grossiste.id', $carton->id)->assertJsonPath('data.0.lignes.0.contenance', 24);
    }

    public function test_le_revendeur_commande_dans_le_catalogue_du_grossiste_et_recommande(): void
    {
        $this->dans($this->depot);
        $carton = Produit::create(['nom' => 'Faniko carton', 'prix_vente' => 3600, 'prix_gros' => 3400, 'taux_tva' => 0, 'stock' => 50]);
        Produit::create(['nom' => 'Lait en rupture', 'prix_vente' => 9000, 'taux_tva' => 0, 'stock' => 0]);
        $this->dans($this->epicerie);
        $sachet = Produit::create(['nom' => 'Faniko', 'prix_vente' => 300, 'taux_tva' => 0, 'stock' => 0]);
        $fournisseur = Fournisseur::create(['nom' => 'Traoré', 'telephone' => '+22376001122'])->id;

        $catalogue = collect($this->api($this->awa)->getJson("/api/fournisseurs/{$fournisseur}/catalogue")->assertOk()->json('data'))->keyBy('nom');
        $this->assertSame(3400, $catalogue['Faniko carton']['prix']);
        $this->assertFalse($catalogue['Lait en rupture']['disponible']);
        $this->assertNull($catalogue['Faniko carton']['chez_moi']);
        $this->api($this->awa)->getJson("/api/fournisseurs/{$fournisseur}/catalogue?q=lait")->assertJsonCount(1, 'data');

        // 3 cartons de 24 : 72 sachets.
        $commande = $this->api($this->awa)->postJson('/api/commandes-fournisseur', ['fournisseur_id' => $fournisseur, 'lignes' => [
            ['produit_id' => $sachet->id, 'quantite' => 72, 'produit_grossiste_id' => $carton->id, 'contenance' => 24, 'prix_estime' => 142],
        ]])->assertCreated()->json();
        $this->api($this->awa)->getJson("/api/fournisseurs/{$fournisseur}/catalogue")
            ->assertJsonPath('data.0.chez_moi.produit_id', $sachet->id)->assertJsonPath('data.0.chez_moi.contenance', 24);

        $this->api($this->grossiste)->postJson("/api/commandes-recues/{$commande['id']}/vue")->assertOk();
        $this->api($this->awa)->getJson('/api/commandes-fournisseur')->assertJsonPath('data.0.statut_libelle', 'Vue');
        $this->api($this->grossiste)->postJson("/api/commandes-recues/{$commande['id']}/confirmer", ['lignes' => [['id' => $commande['lignes'][0]['id'], 'produit_grossiste_id' => $carton->id]]])
            ->assertJsonPath('lignes.0.quantite_confirmee', 3);

        $this->api($this->awa)->postJson("/api/commandes-fournisseur/{$commande['id']}/recommander")->assertCreated()
            ->assertJsonPath('lignes.0.quantite', 72)->assertJsonPath('statut', 'envoyee');
    }

    /** Une commande de riz livrée : 100 000 F, 40 000 payés, 60 000 au crédit du client. Rend l'id du fournisseur. */
    private function livrerACredit(): string
    {
        $this->dans($this->epicerie);
        $fournisseur = Fournisseur::create(['nom' => 'Traoré', 'telephone' => '+22376001122'])->id;
        $commande = $this->api($this->awa)->postJson('/api/commandes-fournisseur', ['fournisseur_id' => $fournisseur, 'lignes' => [['produit_id' => $this->riz->id, 'quantite' => 8]]])->json();
        $this->api($this->grossiste)->postJson("/api/commandes-recues/{$commande['id']}/confirmer", ['lignes' => [['id' => $commande['lignes'][0]['id'], 'produit_grossiste_id' => $this->rizDepot->id, 'quantite' => 8]]])->assertOk();
        $this->api($this->grossiste)->postJson("/api/commandes-recues/{$commande['id']}/livrer", ['montant_paye' => 40000])->assertOk();

        return $fournisseur;
    }

    public function test_sans_ngoni_la_commande_part_sur_whatsapp_et_se_receptionne_a_la_main(): void
    {
        $fournisseur = $this->api($this->awa)->postJson('/api/fournisseurs', ['nom' => 'Kanté', 'telephone' => '+22365000000'])->json('id');

        $commande = $this->api($this->awa)->postJson('/api/commandes-fournisseur', [
            'fournisseur_id' => $fournisseur, 'lignes' => [['produit_id' => $this->riz->id, 'quantite' => 5, 'prix_estime' => 12200]],
        ])->assertCreated()->assertJsonPath('grossiste', null)->json();
        $this->assertStringStartsWith('https://wa.me/22365000000?text=', $commande['whatsapp']);
        $this->assertStringContainsString(rawurlencode('5 × Riz 25kg'), $commande['whatsapp']);
        $this->assertStringContainsString(rawurlencode('ngonipay.ismael-dev.com/app'), $commande['whatsapp']);

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
