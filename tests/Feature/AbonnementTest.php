<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CycleFacturation;
use App\Enums\StatutDemande;
use App\Livewire\Produits\Index as ProduitsIndex;
use App\Livewire\Utilisateurs\Index as UtilisateursIndex;
use App\Mail\DemandeAbonnementMail;
use App\Mail\MessageCompteMail;
use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\NotificationApp;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Il n'y a pas de plan gratuit : essai de 7 jours à l'inscription, puis un
 * abonnement en cours ou la lecture seule.
 */
class AbonnementTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => 'awa@example.com', 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
    }

    private function api(?User $user = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken(($user ?? $this->awa)->createToken('t')->plainTextToken);
    }

    private function expirer(): void
    {
        $this->awa->abonnement()->update(['fin' => now()->subDays(3)->toDateString()]);
    }

    private function vente(?string $reference = null): array
    {
        return [
            'reference_locale' => $reference,
            'lignes' => [['libelle' => 'Repassage', 'prix_unitaire' => 1500, 'quantite' => 1]],
            'moyen_paiement' => 'especes',
        ];
    }

    private function produit(int $stock): Produit
    {
        app(TenantContext::class)->setBoutique($this->boutique->id);

        return Produit::create(['nom' => 'Riz', 'prix_vente' => 500, 'taux_tva' => 0, 'stock' => $stock]);
    }

    public function test_l_inscription_offre_un_essai_de_7_jours(): void
    {
        $abonnement = $this->awa->abonnement;

        $this->assertSame('essai', $abonnement->plan);
        $this->assertSame(now()->addDays(7)->toDateString(), $abonnement->fin->toDateString());
        $this->assertTrue($abonnement->estEnCours());

        $this->api()->getJson('/api/abonnement')->assertOk()
            ->assertJsonPath('data.plan', 'essai')
            ->assertJsonPath('data.est_en_cours', true)
            ->assertJsonPath('data.est_proprietaire', true)
            ->assertJsonPath('data.peut_demander', true);
    }

    public function test_apres_l_essai_on_consulte_mais_on_n_agit_plus(): void
    {
        $this->expirer();

        $this->api()->postJson('/api/produits', ['nom' => 'Riz', 'prix_vente' => 500])
            ->assertForbidden()->assertJsonPath('code', 'ABONNEMENT_EXPIRE');
        $this->api()->postJson('/api/clients', ['nom' => 'Fatou'])
            ->assertForbidden()->assertJsonPath('code', 'ABONNEMENT_EXPIRE');
        $this->api()->postJson('/api/sessions-caisse', ['fond_initial' => 0])
            ->assertForbidden();

        $this->api()->getJson('/api/produits')->assertOk();
        $this->api()->getJson('/api/ventes')->assertOk();
        $this->api()->getJson('/api/abonnement')->assertOk()->assertJsonPath('data.est_en_cours', false);
    }

    public function test_apres_l_essai_la_caisse_vend_le_catalogue_jusqu_a_epuisement(): void
    {
        $riz = $this->produit(stock: 2);
        $this->expirer();

        $vente = fn (int $quantite): array => [
            'lignes' => [['produit_id' => $riz->id, 'quantite' => $quantite]],
            'moyen_paiement' => 'especes',
        ];

        $this->api()->postJson('/api/ventes', $vente(2))->assertCreated();
        $this->assertSame(0, $riz->fresh()->stock);

        // Stock vide, et plus moyen de le remonter : c'est là que l'abonnement se décide.
        $this->api()->postJson('/api/ventes', $vente(1))->assertUnprocessable();
        $this->api()->postJson("/api/produits/{$riz->id}/ajuster-stock", ['stock' => 10, 'motif' => 'Arrivage'])
            ->assertForbidden()->assertJsonPath('code', 'ABONNEMENT_EXPIRE');
    }

    public function test_apres_l_essai_les_lignes_libres_sont_refusees(): void
    {
        $riz = $this->produit(stock: 5);
        $this->expirer();

        $this->api()->postJson('/api/ventes', $this->vente())
            ->assertForbidden()->assertJsonPath('code', 'ABONNEMENT_EXPIRE')
            ->assertJsonFragment(['message' => 'Votre essai gratuit est terminé : la caisse vend encore les articles du catalogue, mais plus hors catalogue. Abonnez-vous pour continuer.']);

        // Mêlée au catalogue, la ligne libre fait refuser toute la vente.
        $this->api()->postJson('/api/ventes', [
            'lignes' => [['produit_id' => $riz->id, 'quantite' => 1], ['libelle' => 'Livraison', 'prix_unitaire' => 500, 'quantite' => 1]],
            'moyen_paiement' => 'especes',
        ])->assertForbidden();

        $this->assertSame(0, Vente::withoutBoutiqueScope()->count());
        $this->assertSame(5, $riz->fresh()->stock);
    }

    public function test_apres_l_essai_une_dette_client_s_encaisse_encore(): void
    {
        $riz = $this->produit(stock: 5);
        $fatou = $this->api()->postJson('/api/clients', ['nom' => 'Fatou'])->assertCreated()->json('id');
        $this->api()->postJson('/api/ventes', [
            'client_id' => $fatou,
            'lignes' => [['produit_id' => $riz->id, 'quantite' => 2]],
            'moyen_paiement' => 'credit_client',
        ])->assertCreated();
        $this->expirer();

        $this->api()->postJson("/api/clients/{$fatou}/reglements", ['montant' => 500, 'moyen_paiement' => 'especes'])
            ->assertCreated();
    }

    public function test_une_vente_deja_enregistree_se_rejoue_meme_apres_expiration(): void
    {
        $reference = (string) Str::uuid();
        $this->api()->postJson('/api/ventes', $this->vente($reference))->assertCreated();

        $this->expirer();

        $this->api()->postJson('/api/ventes', $this->vente($reference))->assertCreated();
        $this->assertSame(1, Vente::withoutBoutiqueScope()->count());
    }

    public function test_actif_jusqu_a_la_fin_du_dernier_jour(): void
    {
        $this->awa->abonnement()->update(['fin' => now()->toDateString()]);

        $this->api()->postJson('/api/ventes', $this->vente())->assertCreated();
    }

    public function test_l_essai_ne_couvre_qu_une_boutique_et_ne_se_relance_pas(): void
    {
        $this->api()->postJson('/api/boutiques', ['nom' => 'Deuxième'])
            ->assertForbidden()->assertJsonPath('code', 'LIMITE_BOUTIQUES');

        $this->expirer();
        $this->api()->postJson('/api/boutiques', ['nom' => 'Deuxième'])
            ->assertForbidden()->assertJsonPath('code', 'ABONNEMENT_EXPIRE');

        $this->assertSame(1, Abonnement::count());
    }

    public function test_le_catalogue_des_plans_est_public(): void
    {
        $reponse = $this->getJson('/api/plans')->assertOk()->assertJsonPath('support.whatsapp', '+33605758494');
        $reponse->assertJsonPath('support.paiement.0', ['telephone' => '+22373136789', 'moyens' => ['Orange Money', 'Wave']])
            ->assertJsonPath('support.paiement.1.telephone', '+22374988201');
        $reponse->assertJsonPath('support.whatsapps', ['+33605758494', '+22374988201']);

        $plans = collect($reponse->json('data'))->keyBy('code');
        $this->assertTrue($plans['essai']['est_essai']);
        $this->assertSame(7, $plans['essai']['jours_essai']);
        $this->assertSame(4000, $plans['basic']['tarifs'][0]['montant']);
        $this->assertSame(['monthly', 'quarterly', 'biannual', 'yearly'], array_column($plans['pro']['tarifs'], 'cycle'));
    }

    public function test_une_demande_previent_l_exploitant_sans_rien_activer(): void
    {
        Storage::fake('local');
        $this->expirer();

        $this->api()->post('/api/abonnement/demandes', [
            'plan' => 'basic', 'cycle' => 'quarterly', 'moyen' => 'orange_money',
            'preuve' => UploadedFile::fake()->image('recu.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('code', 'DEMANDE_EN_ATTENTE')
            ->assertJsonPath('data.montant', 11000)
            ->assertJsonPath('data.preuve_jointe', true);

        $demande = DemandeAbonnement::firstOrFail();
        Storage::disk('local')->assertExists($demande->preuve_chemin);

        Mail::assertSent(DemandeAbonnementMail::class, fn ($mail) => $mail->hasTo('ismalsacko@yahoo.fr'));
        $this->assertFalse($this->awa->abonnement()->first()->estEnCours());

        // Une seule demande en attente à la fois ; l'essai ne se demande pas.
        $this->api()->postJson('/api/abonnement/demandes', ['plan' => 'pro'])->assertStatus(422);
        $demande->update(['statut' => StatutDemande::Annulee]);
        $this->api()->postJson('/api/abonnement/demandes', ['plan' => 'essai'])->assertStatus(422);
    }

    public function test_seul_l_admin_demande_un_abonnement(): void
    {
        $caissier = User::create([
            'boutique_id' => $this->boutique->id, 'name' => 'Caissier', 'phone' => '+22370000001', 'password' => 'password123',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        $caissier->assignRole('caissier');

        $this->api($caissier)->postJson('/api/abonnement/demandes', ['plan' => 'basic'])->assertForbidden();
        $this->api($caissier)->getJson('/api/abonnement')->assertOk()->assertJsonPath('data.peut_demander', false);
    }

    public function test_l_approbation_active_le_plan_pour_la_duree_payee(): void
    {
        $this->expirer();
        $service = app(AbonnementService::class);
        $exploitant = User::create(['name' => 'Exploitant', 'phone' => '+33605758494', 'password' => 'x']);

        $demande = $service->soumettre($this->boutique, $this->awa, 'basic', CycleFacturation::Trimestriel);
        $service->approuver($demande, $exploitant);

        $abonnement = $this->awa->abonnement()->first();
        $this->assertSame('basic', $abonnement->plan);
        $this->assertSame(now()->addMonths(3)->toDateString(), $abonnement->fin->toDateString());
        $this->assertSame(StatutDemande::Approuvee, $demande->fresh()->statut);

        // Le commerçant l'apprend : cloche, push et e-mail.
        $avis = NotificationApp::where('user_id', $this->awa->id)->latest('id')->first();
        $this->assertSame('Votre abonnement Basic est activé', $avis->titre);
        $this->assertStringContainsString('jusqu’au '.now()->addMonths(3)->format('d/m/Y'), $avis->message);
        $this->assertSame('/abonnement', $avis->lien);
        Mail::assertSent(MessageCompteMail::class, fn ($mail) => $mail->hasTo('awa@example.com') && $mail->titre === $avis->titre);

        // Une demande tranchée ne se retranche pas.
        $this->expectException(ValidationException::class);
        $service->approuver($demande->fresh(), $exploitant);
    }

    public function test_un_refus_et_un_acces_offert_sont_annonces_au_commercant(): void
    {
        $service = app(AbonnementService::class);
        $exploitant = User::create(['name' => 'Exploitant', 'phone' => '+33605758494', 'password' => 'x']);

        $service->refuser($service->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel), $exploitant, 'Paiement non reçu');
        $refus = NotificationApp::where('user_id', $this->awa->id)->latest('id')->first();
        $this->assertSame('Demande d’abonnement non validée', $refus->titre);
        $this->assertStringContainsString('Paiement non reçu', $refus->message);

        $service->accorder($this->awa, 'pro', null, $exploitant);
        $this->assertSame('Votre abonnement Pro est activé', NotificationApp::where('user_id', $this->awa->id)->latest('id')->first()->titre);
        $this->assertStringContainsString('sans échéance', NotificationApp::where('user_id', $this->awa->id)->latest('id')->first()->message);
    }

    public function test_le_meme_plan_prolonge_et_un_autre_plan_convertit_les_jours(): void
    {
        $service = app(AbonnementService::class);
        $exploitant = User::create(['name' => 'Exploitant', 'phone' => '+33605758494', 'password' => 'x']);
        $this->awa->abonnement()->update(['plan' => 'basic', 'fin' => now()->addDays(30)->toDateString()]);

        // Même plan : un mois s'ajoute à l'échéance.
        $this->assertSame(
            now()->addDays(30)->addMonth()->toDateString(),
            $service->finProjetee($this->awa->id, 'basic', 1)->toDateString(),
        );

        // Basic (4 000/mois) vers Pro (10 000/mois) : 30 jours Basic = 12 jours Pro.
        $demande = $service->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel);
        $service->approuver($demande, $exploitant);
        $this->assertSame(now()->addMonth()->addDays(12)->toDateString(), $this->awa->abonnement()->first()->fin->toDateString());
    }

    public function test_un_refus_laisse_le_plan_inchange(): void
    {
        $service = app(AbonnementService::class);
        $exploitant = User::create(['name' => 'Exploitant', 'phone' => '+33605758494', 'password' => 'x']);

        $demande = $service->soumettre($this->boutique, $this->awa, 'basic', CycleFacturation::Mensuel);
        $service->refuser($demande, $exploitant, 'Paiement introuvable');

        $this->assertSame('essai', $this->awa->abonnement()->first()->plan);
        $this->api()->getJson('/api/abonnement/demandes')->assertOk()
            ->assertJsonPath('data.0.statut', 'refusee')
            ->assertJsonPath('data.0.note_decision', 'Paiement introuvable');
    }

    public function test_le_back_office_bloque_les_modifications_apres_l_essai(): void
    {
        $this->expirer();
        $this->actingAs($this->awa);
        $avant = Produit::withoutBoutiqueScope()->count(); // les articles de départ

        Livewire::test(ProduitsIndex::class)
            ->call('nouveauProduit')
            ->set('nom', 'Riz')->set('prix_vente', '500')->set('taux_tva', '18')->set('stock', '3')->set('seuil_alerte', '1')
            ->call('enregistrer')
            ->assertDispatched('abonnement-expire');

        $this->assertSame($avant, Produit::withoutBoutiqueScope()->count(), 'aucun article créé');
        $this->get('/tableau-de-bord')->assertOk()->assertSee('Essai gratuit terminé');
    }

    public function test_les_seances_de_caisse_sont_reservees_au_pro(): void
    {
        // Essai : tout est inclus.
        $this->api()->getJson('/api/abonnement')->assertJsonPath('data.fonctionnalites', ['seances_caisse', 'statistiques_avancees']);
        $session = $this->api()->postJson('/api/sessions-caisse', ['fond_initial' => 5000])->assertCreated();

        // Passé en Basic : plus de nouvelle séance, mais la vente passe sans,
        // et la séance ouverte pendant l'essai se clôture.
        $this->awa->abonnement()->update(['plan' => 'basic', 'fin' => now()->addMonth()->toDateString()]);
        $this->api()->getJson('/api/abonnement')->assertJsonPath('data.fonctionnalites', []);
        $this->api()->putJson("/api/sessions-caisse/{$session->json('id')}/fermer", ['fond_final' => 5000])->assertOk();
        $this->api()->postJson('/api/sessions-caisse', ['fond_initial' => 5000])
            ->assertForbidden()->assertJsonPath('code', 'FONCTION_PRO');
        $this->api()->postJson('/api/ventes', $this->vente((string) Str::uuid()))
            ->assertCreated()->assertJsonPath('session_caisse_id', null);

        // Pro : de nouveau permis.
        $this->awa->abonnement()->update(['plan' => 'pro']);
        $this->api()->postJson('/api/sessions-caisse', ['fond_initial' => 5000])->assertCreated();
    }

    public function test_le_plan_limite_la_taille_de_l_equipe(): void
    {
        $this->actingAs($this->awa);

        foreach (['70000001', '70000002'] as $i => $telephone) {
            Livewire::test(UtilisateursIndex::class)
                ->call('nouveauCompte')->set('name', "Membre $i")->set('telephone', $telephone)
                ->set('password', 'password123')->set('role', 'caissier')->call('enregistrer')
                ->assertHasNoErrors();
        }

        // Essai : 3 membres, propriétaire compris.
        Livewire::test(UtilisateursIndex::class)
            ->call('nouveauCompte')->set('name', 'De trop')->set('telephone', '70000003')
            ->set('password', 'password123')->set('role', 'caissier')->call('enregistrer')
            ->assertHasErrors('telephone');

        $this->assertNull(User::where('name', 'De trop')->first());
    }

    public function test_un_compte_sans_boutique_en_cree_une_et_son_essai_demarre(): void
    {
        $sansBoutique = User::create(['name' => 'Inscrit', 'phone' => '+22370000055', 'password' => 'password123']);

        $this->api($sansBoutique)->getJson('/api/moi')->assertOk()->assertJsonPath('user.boutique', null);
        $id = $this->api($sansBoutique)->postJson('/api/boutiques', ['nom' => 'Ma boutique', 'pays' => 'ML'])->assertCreated()->json('id');
        $this->api($sansBoutique)->putJson("/api/boutiques/{$id}/par-defaut")->assertOk();

        $this->assertSame('essai', $sansBoutique->fresh()->abonnement->plan);
        $this->assertTrue($sansBoutique->fresh()->abonnement->estEnCours());
        $this->api($sansBoutique)->getJson('/api/abonnement')->assertOk()->assertJsonPath('data.est_proprietaire', true);
    }

    public function test_un_proprietaire_dont_les_boutiques_sont_fermees_peut_en_rouvrir_une(): void
    {
        $this->expirer();
        $this->boutique->delete();

        $this->api()->postJson('/api/boutiques', ['nom' => 'Réouverture'])->assertCreated();

        // Pas de nouvel essai : l'abonnement reste expiré, la boutique en lecture seule.
        $this->assertFalse($this->awa->abonnement()->first()->estEnCours());
        $this->assertSame(1, Abonnement::count());
    }
}
