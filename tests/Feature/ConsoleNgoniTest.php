<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CycleFacturation;
use App\Enums\StatutDemande;
use App\Mail\DemandeAbonnementMail;
use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\JournalAbonnement;
use App\Models\Plan;
use App\Models\PlanTarif;
use App\Models\User;
use App\Models\Vente;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Le contrat v1 de la Console Ngoni, commune à toutes les applications. */
class ConsoleNgoniTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    private array $cle = ['Authorization' => 'Bearer cle-de-test-console', 'X-Console-Operateur' => 'Ismaila'];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['services.console_ngoni.cle' => 'cle-de-test-console']);
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        User::create(['name' => 'Ismael', 'phone' => '+33605758494', 'password' => 'password123'])
            ->forceFill(['est_admin_plateforme' => true])->save();
    }

    public function test_sans_la_bonne_cle_la_console_est_refusee(): void
    {
        $this->getJson('/api/console/v1/resume')->assertUnauthorized();
        $this->getJson('/api/console/v1/resume', ['Authorization' => 'Bearer autre'])->assertUnauthorized();
    }

    public function test_resume_comptes_et_formules_selon_le_contrat(): void
    {
        $this->getJson('/api/console/v1/resume', $this->cle)->assertOk()
            ->assertJsonPath('application.code', 'ngoni-caisse')
            ->assertJsonPath('comptes', 1)
            ->assertJsonPath('essais', 1);

        $compte = $this->getJson('/api/console/v1/comptes?filtre=essai', $this->cle)->assertOk()->json('data.0');
        $this->assertSame((string) $this->awa->id, $compte['id']);
        $this->assertSame('Pressing Awa', $compte['nom']);

        $pro = collect($this->getJson('/api/console/v1/formules', $this->cle)->assertOk()->json('data'))->firstWhere('code', 'pro');
        $this->assertSame(10000, collect($pro['tarifs'])->firstWhere('mois', 1)['montant']);
    }

    public function test_approuver_une_demande_active_l_abonnement(): void
    {
        $demande = app(AbonnementService::class)->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel);

        $ligne = $this->getJson('/api/console/v1/demandes', $this->cle)->assertOk()->json('data.0');
        $this->assertSame((string) $demande->id, $ligne['id']);
        $this->assertSame(1, $ligne['mois']);

        $this->postJson("/api/console/v1/demandes/{$demande->id}/approuver", [], $this->cle)->assertOk()
            ->assertJsonPath('demande.statut', 'approuvee');
        $this->assertSame('pro', Abonnement::where('user_id', $this->awa->id)->value('plan'));
        // Déjà traitée : refus lisible.
        $this->postJson("/api/console/v1/demandes/{$demande->id}/approuver", [], $this->cle)->assertStatus(422);
    }

    public function test_accorder_sans_echeance_et_regler_un_prix(): void
    {
        $this->postJson("/api/console/v1/comptes/{$this->awa->id}/abonnement", ['formule' => 'pro', 'fin' => null, 'note' => 'Partenaire'], $this->cle)->assertOk();
        $abo = Abonnement::where('user_id', $this->awa->id)->first();
        $this->assertSame('pro', $abo->plan);
        $this->assertNull($abo->fin);

        $pro = collect($this->patchJson('/api/console/v1/formules/pro', ['tarifs' => [['mois' => 1, 'montant' => 12000]]], $this->cle)
            ->assertOk()->json('data'))->firstWhere('code', 'pro');
        $this->assertSame(12000, collect($pro['tarifs'])->firstWhere('mois', 1)['montant']);
    }

    public function test_une_autre_application_enregistre_sa_vente_dans_la_boutique_de_l_exploitant(): void
    {
        config(['ecaisse.boutique_ventes_abonnements' => $this->boutique->id]);
        $vente = [
            'reference' => 'demande-42', 'application' => 'ngoni-sante',
            'client' => ['nom' => 'CSCom de Banconi', 'telephone' => '+22370000001', 'adresse' => 'Ngoni Santé · Centre de santé'],
            'libelle' => 'Abonnement Ngoni Santé · Pro · 3 mois', 'montant' => 42000, 'moyen' => 'orange_money',
        ];

        $id = $this->postJson('/api/console/v1/ventes', $vente, $this->cle)->assertCreated()->json('vente.id');
        // Rejouée, elle ne double pas.
        $this->assertSame($id, $this->postJson('/api/console/v1/ventes', $vente, $this->cle)->assertCreated()->json('vente.id'));
        $this->assertSame(1, Vente::withoutGlobalScopes()->where('boutique_id', $this->boutique->id)->count());
        $this->assertSame(42000, (int) Vente::withoutGlobalScopes()->find($id)->total);
    }

    public function test_valider_a_un_montant_negocie(): void
    {
        $demande = app(AbonnementService::class)->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel);

        $this->postJson("/api/console/v1/demandes/{$demande->id}/approuver", ['montant' => 9000, 'note' => 'Remise négociée'], $this->cle)->assertOk();
        $demande->refresh();
        $this->assertSame(9000, (int) $demande->montant);
        $this->assertStringContainsString('demandé : 10 000', (string) $demande->note_decision);
        $this->assertStringContainsString('Remise négociée', (string) $demande->note_decision);
    }

    public function test_les_comptes_de_l_exploitant_sont_hors_des_chiffres(): void
    {
        $this->getJson('/api/console/v1/resume', $this->cle)->assertJsonPath('comptes', 1);
        $this->postJson("/api/console/v1/comptes/{$this->awa->id}/interne", ['interne' => true], $this->cle)->assertOk();

        $this->getJson('/api/console/v1/resume', $this->cle)->assertJsonPath('comptes', 0)->assertJsonPath('internes', 1);
        $this->assertSame([], $this->getJson('/api/console/v1/comptes', $this->cle)->json('data'));
        $this->assertTrue($this->getJson('/api/console/v1/comptes?filtre=interne', $this->cle)->json('data.0.interne'));
    }

    // ── Ajouts v1.1 ──────────────────────────────────────────────────────

    public function test_le_resume_annonce_capacites_et_moyens(): void
    {
        $app = $this->getJson('/api/console/v1/resume', $this->cle)->assertOk()->json('application');
        foreach (['preuve', 'revoquer', 'approuver_a_vie', 'options_compte', 'recettes', 'historique'] as $capacite) {
            $this->assertContains($capacite, $app['capacites']);
        }
        $this->assertNotContains('creer_formule', $app['capacites']);
        $this->assertSame('Orange Money', collect($app['moyens'])->firstWhere('code', 'orange_money')['libelle']);
    }

    public function test_demandes_recherche_annulees_et_champs_en_plus(): void
    {
        $service = app(AbonnementService::class);
        $demande = $service->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel, 'wave', 'ID: WAVE123', preuveNote: 'Envoyé depuis le 76 00 82 01');

        $ligne = $this->getJson('/api/console/v1/demandes?q=WAVE123', $this->cle)->assertOk()->json('data.0');
        $this->assertSame((string) $demande->id, $ligne['id']);
        $this->assertSame('ID: WAVE123', $ligne['note']);
        $this->assertSame('Envoyé depuis le 76 00 82 01', $ligne['preuve_note']);
        $this->assertSame('wave', $ligne['moyen']);
        $this->assertSame(0, $ligne['frais']);
        $this->assertFalse($ligne['automatique']);
        $this->assertSame([], $this->getJson('/api/console/v1/demandes?q=introuvable', $this->cle)->json('data'));

        $service->annuler($demande);
        $this->assertSame([], $this->getJson('/api/console/v1/demandes', $this->cle)->json('data'));
        $this->assertSame('annulee', $this->getJson('/api/console/v1/demandes?statut=annulee', $this->cle)->json('data.0.statut'));

        // Payée en ligne et validée seule : automatique, avec la référence du prestataire.
        $enLigne = $service->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel, prevenir: false);
        $enLigne->update(['moyen' => 'jeko_wave', 'jeko_paiement_id' => 'jk-1', 'jeko_transaction_id' => 'TX-99', 'frais_mobile' => 200]);
        $service->approuver($enLigne, null, 'Payé par Wave via Jèko (TX-99)');
        $ligne = $this->getJson('/api/console/v1/demandes?statut=approuvee', $this->cle)->json('data.0');
        $this->assertTrue($ligne['automatique']);
        $this->assertSame('TX-99', $ligne['reference']);
        $this->assertSame(200, $ligne['frais']);
        $this->assertSame('jeko_wave', $ligne['moyen']);
        $this->assertSame('Payé par Wave via Jèko (TX-99)', $ligne['note_decision']);
    }

    public function test_la_preuve_est_servie_avec_son_type(): void
    {
        Storage::fake('local');
        $demande = app(AbonnementService::class)->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel, preuve: UploadedFile::fake()->image('recu.png'));

        $this->assertTrue($this->getJson('/api/console/v1/demandes', $this->cle)->json('data.0.preuve'));
        $this->get("/api/console/v1/demandes/{$demande->id}/preuve", $this->cle)->assertOk()->assertHeader('Content-Type', 'image/png');

        $sans = DemandeAbonnement::create([...$demande->only(['user_id', 'boutique_id', 'plan', 'cycle', 'mois', 'montant', 'devise']), 'statut' => StatutDemande::Refusee]);
        $this->get("/api/console/v1/demandes/{$sans->id}/preuve", $this->cle)->assertNotFound();
    }

    public function test_approuver_avec_moyen_reference_et_a_vie(): void
    {
        config(['ecaisse.boutique_ventes_abonnements' => $this->boutique->id]);
        $demande = app(AbonnementService::class)->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel);

        $ligne = $this->postJson("/api/console/v1/demandes/{$demande->id}/approuver", ['moyen' => 'orange_money', 'reference' => 'MP261028.1', 'a_vie' => true], $this->cle)
            ->assertOk()->json('demande');
        $this->assertSame('approuvee', $ligne['statut']);
        $this->assertSame('orange_money', $ligne['moyen']);
        $this->assertSame('MP261028.1', $ligne['reference']);
        $this->assertSame('Console · Ismaila', $ligne['par']);
        $this->assertNotNull($ligne['recu_numero']);
        $this->assertNull(Abonnement::where('user_id', $this->awa->id)->first()->fin);
        // La vente dans la boutique de l'exploitant, au montant validé.
        $this->assertSame(10000, (int) Vente::withoutGlobalScopes()->where('boutique_id', $this->boutique->id)->value('total'));

        $this->postJson("/api/console/v1/demandes/{$demande->id}/approuver", ['moyen' => 'cheque'], $this->cle)->assertStatus(422);
    }

    public function test_un_montant_negocie_ne_change_rien_si_la_validation_est_refusee(): void
    {
        $demande = app(AbonnementService::class)->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel);
        app(AbonnementService::class)->refuser($demande, User::where('est_admin_plateforme', true)->first(), 'Non reçu');

        $this->postJson("/api/console/v1/demandes/{$demande->id}/approuver", ['montant' => 1], $this->cle)->assertStatus(422);
        $this->assertSame(10000, (int) $demande->fresh()->montant);
        $this->assertNull($demande->fresh()->decide_par_nom);
    }

    public function test_refuser_note_qui_a_decide(): void
    {
        $demande = app(AbonnementService::class)->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel);
        $this->postJson("/api/console/v1/demandes/{$demande->id}/refuser", ['motif' => 'Montant incomplet'], $this->cle)->assertOk();

        $ligne = $this->getJson('/api/console/v1/demandes?statut=refusee', $this->cle)->json('data.0');
        $this->assertSame('Montant incomplet', $ligne['motif_refus']);
        $this->assertSame('Console · Ismaila', $ligne['par']);
    }

    public function test_accorder_avec_un_montant_fait_un_recu_et_une_vente(): void
    {
        config(['ecaisse.boutique_ventes_abonnements' => $this->boutique->id]);
        // Payée hors ligne, en attente : c'est elle qui porte le reçu.
        $attente = app(AbonnementService::class)->soumettre($this->boutique, $this->awa, 'basic', CycleFacturation::Mensuel, 'wave');
        $fin = today()->addMonths(3)->addDays(4)->toDateString();

        $recu = $this->postJson("/api/console/v1/comptes/{$this->awa->id}/abonnement", [
            'formule' => 'pro', 'debut' => today()->toDateString(), 'fin' => $fin, 'montant' => 25000, 'moyen' => 'wave', 'reference' => 'W-777', 'note' => 'Promo',
        ], $this->cle)->assertOk()->json('recu_numero');

        $abo = Abonnement::where('user_id', $this->awa->id)->first();
        $this->assertSame('pro', $abo->plan);
        $this->assertSame($fin, $abo->fin->toDateString());
        $attente->refresh();
        $this->assertSame(StatutDemande::Approuvee, $attente->statut);
        $this->assertSame($recu, $attente->recu_numero);
        $this->assertSame(25000, (int) $attente->montant);
        $this->assertSame('W-777', $attente->referencePaiement());
        $this->assertSame($fin, $attente->periode_fin->toDateString());
        $this->assertSame('Console · Ismaila', $attente->decide_par_nom);
        $this->assertSame(1, DemandeAbonnement::count());
        $this->assertSame(25000, (int) Vente::withoutGlobalScopes()->where('boutique_id', $this->boutique->id)->value('total'));

        $journal = JournalAbonnement::where('user_id', $this->awa->id)->first();
        $this->assertSame(JournalAbonnement::ACCORD, $journal->type);
        $this->assertSame('Console · Ismaila', $journal->par);
        $this->assertSame($attente->id, $journal->demande_id);
    }

    public function test_accorder_sans_montant_ne_fait_ni_recu_ni_vente(): void
    {
        config(['ecaisse.boutique_ventes_abonnements' => $this->boutique->id]);
        $this->postJson("/api/console/v1/comptes/{$this->awa->id}/abonnement", ['formule' => 'pro', 'fin' => today()->addMonth()->toDateString()], $this->cle)
            ->assertOk()->assertJsonPath('recu_numero', null);
        $this->assertSame(0, DemandeAbonnement::count());
        $this->assertSame(0, Vente::withoutGlobalScopes()->count());
        $this->assertSame(1, JournalAbonnement::where('type', 'accord')->count());
    }

    public function test_revoquer_arrete_l_abonnement_maintenant(): void
    {
        $this->postJson("/api/console/v1/comptes/{$this->awa->id}/revoquer", ['motif' => 'Fraude'], $this->cle)->assertOk();
        $abo = Abonnement::where('user_id', $this->awa->id)->first();
        $this->assertFalse($abo->estEnCours());

        $revocation = collect($this->getJson("/api/console/v1/comptes/{$this->awa->id}", $this->cle)->json('historique'))->firstWhere('type', 'revocation');
        $this->assertSame('Console · Ismaila', $revocation['par']);
        $this->assertSame('Fraude', $revocation['note']);

        $sansAbonnement = User::create(['name' => 'Moussa', 'phone' => '+22370000009', 'password' => 'password123']);
        $this->postJson("/api/console/v1/comptes/{$sansAbonnement->id}/revoquer", [], $this->cle)->assertNotFound();
    }

    public function test_fiche_du_compte_details_historique_et_options(): void
    {
        $service = app(AbonnementService::class);
        $demande = $service->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel, 'wave');
        $this->postJson("/api/console/v1/demandes/{$demande->id}/approuver", ['reference' => 'REF-1'], $this->cle)->assertOk();

        $fiche = $this->getJson("/api/console/v1/comptes/{$this->awa->id}", $this->cle)->assertOk()->json();
        $this->assertSame((string) $this->awa->id, $fiche['compte']['id']);
        $this->assertSame('pro', $fiche['compte']['formule']);
        $this->assertContains('Boutique Pressing Awa', array_column($fiche['details'], 'libelle'));
        $types = array_column($fiche['historique'], 'type');
        $this->assertContains('paiement', $types);
        $this->assertContains('essai', $types);
        $paiement = collect($fiche['historique'])->firstWhere('type', 'paiement');
        $this->assertSame('REF-1', $paiement['reference']);
        $this->assertSame('Console · Ismaila', $paiement['par']);
        $this->assertSame('fonctions_accordees', $fiche['options'][0]['cle']);
        $this->assertSame('choix_multiple', $fiche['options'][0]['type']);

        $this->getJson('/api/console/v1/comptes/'.User::where('est_admin_plateforme', true)->value('id'), $this->cle)->assertNotFound();
    }

    public function test_options_du_compte_fonctions_accordees(): void
    {
        $this->postJson("/api/console/v1/comptes/{$this->awa->id}/abonnement", ['formule' => 'basic', 'fin' => today()->addMonth()->toDateString()], $this->cle)->assertOk();
        $basic = Plan::parCode('basic');
        $horsFormule = collect(array_keys(Plan::FONCTIONNALITES))->first(fn (string $f) => ! $basic->inclut($f));
        $incluse = collect(array_keys(Plan::FONCTIONNALITES))->first(fn (string $f) => $basic->inclut($f));

        $options = $this->postJson("/api/console/v1/comptes/{$this->awa->id}/options", ['fonctions_accordees' => array_filter([$horsFormule, $incluse])], $this->cle)
            ->assertOk()->json('options');
        $this->assertSame([$horsFormule], $options[0]['valeur']);
        $abo = Abonnement::where('user_id', $this->awa->id)->first();
        $this->assertTrue($abo->inclut($horsFormule));
        // Rien d'autre ne change : formule, échéance, accord manuel.
        $this->assertSame('basic', $abo->plan);
        $this->assertSame(today()->addMonth()->toDateString(), $abo->fin->toDateString());

        $this->postJson("/api/console/v1/comptes/{$this->awa->id}/options", ['fonctions_accordees' => ['inconnue']], $this->cle)->assertStatus(422);
    }

    public function test_recettes_sur_une_periode(): void
    {
        $service = app(AbonnementService::class);
        $demande = $service->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel, 'orange_money');
        $this->postJson("/api/console/v1/demandes/{$demande->id}/approuver", ['reference' => 'OM-1'], $this->cle)->assertOk();
        $this->postJson("/api/console/v1/comptes/{$this->awa->id}/abonnement", ['formule' => 'pro', 'fin' => today()->addYear()->toDateString(), 'montant' => 5000, 'moyen' => 'especes'], $this->cle)->assertOk();

        $r = $this->getJson('/api/console/v1/recettes?du='.today()->toDateString().'&au='.today()->toDateString(), $this->cle)->assertOk()->json();
        $this->assertCount(2, $r['data']);
        $ligne = collect($r['data'])->firstWhere('reference', 'OM-1');
        $this->assertSame(10000, $ligne['montant']);
        $this->assertSame('orange_money', $ligne['moyen']);
        $this->assertFalse($ligne['automatique']);
        $this->assertSame('Pressing Awa', $ligne['compte']['nom']);
        $this->assertSame([['devise' => 'XOF', 'total' => 15000, 'frais' => 0]], $r['totaux']);

        $this->assertSame([], $this->getJson('/api/console/v1/recettes?du=2020-01-01&au=2020-01-31', $this->cle)->json('data'));
    }

    public function test_formules_champs_tarif_a_vie_et_duree_retiree(): void
    {
        $pro = collect($this->getJson('/api/console/v1/formules', $this->cle)->json('data'))->firstWhere('code', 'pro');
        $this->assertSame(['max_boutiques', 'fonctionnalites', 'texte_a_vie', 'a_vie_debut', 'a_vie_fin'], array_column($pro['champs'], 'cle'));
        $essai = collect($this->getJson('/api/console/v1/formules', $this->cle)->json('data'))->firstWhere('code', 'essai');
        $this->assertSame(['max_boutiques'], array_column($essai['champs'], 'cle'));

        $fonctions = [Plan::VENTE_CREDIT, Plan::COFFRE];
        $pro = collect($this->patchJson('/api/console/v1/formules/pro', [
            'champs' => ['max_boutiques' => 8, 'fonctionnalites' => $fonctions, 'texte_a_vie' => 'Offre de lancement'],
            'tarifs' => [['mois' => 3, 'montant' => null], ['mois' => 0, 'montant' => 150000]],
        ], $this->cle)->assertOk()->json('data'))->firstWhere('code', 'pro');

        $plan = Plan::parCode('pro');
        $this->assertSame(8, $plan->max_boutiques);
        $this->assertEqualsCanonicalizing($fonctions, $plan->fonctionnalites);
        $this->assertSame(150000, $plan->prix_a_vie);
        $this->assertSame('Offre de lancement', $plan->texte_a_vie);
        $this->assertFalse((bool) PlanTarif::where('plan_id', $plan->id)->where('cycle', 'quarterly')->value('est_actif'));
        $this->assertNull(collect($pro['tarifs'])->firstWhere('mois', 3));
        $this->assertSame(150000, collect($pro['tarifs'])->firstWhere('mois', 0)['montant']);
        $this->assertSame(10000, collect($pro['tarifs'])->firstWhere('mois', 1)['montant']);

        // Le prix à vie retiré : plus de tarif « mois 0 ».
        $pro = collect($this->patchJson('/api/console/v1/formules/pro', ['tarifs' => [['mois' => 0, 'montant' => null]]], $this->cle)->json('data'))->firstWhere('code', 'pro');
        $this->assertNull(collect($pro['tarifs'])->firstWhere('mois', 0));
    }

    public function test_le_mail_de_demande_pointe_vers_la_console_ngoni(): void
    {
        $demande = app(AbonnementService::class)->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel);
        $html = (new DemandeAbonnementMail($demande))->render();
        $this->assertStringContainsString('https://console.ismaeldev.fr/demandes?app=ngoni-caisse', $html);
    }

    public function test_prolonger_l_essai_reste_un_essai_sans_recu_ni_vente(): void
    {
        $demande = app(AbonnementService::class)->soumettre($this->boutique, $this->awa, 'pro', CycleFacturation::Mensuel);
        $fin = today()->addDays(40)->toDateString();
        $this->assertContains('prolonger_essai', $this->getJson('/api/console/v1/resume', $this->cle)->json('application.capacites'));
        $this->assertTrue($this->getJson("/api/console/v1/comptes/{$this->awa->id}", $this->cle)->json('compte.essai_prolongeable'));

        $this->postJson("/api/console/v1/comptes/{$this->awa->id}/essai", ['fin' => $fin, 'note' => 'Découvre encore'], $this->cle)->assertOk()
            ->assertJsonPath('compte.formule', Plan::ESSAI)->assertJsonPath('compte.fin', $fin)->assertJsonPath('compte.en_cours', true);

        $abo = Abonnement::where('user_id', $this->awa->id)->first();
        $this->assertTrue($abo->estEssai() && $abo->estEnCours());
        $this->assertSame(StatutDemande::EnAttente, $demande->fresh()->statut);
        $this->assertSame(0, Vente::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('notifications_app', ['user_id' => $this->awa->id, 'titre' => 'Votre essai gratuit est prolongé']);
        $historique = $this->getJson("/api/console/v1/comptes/{$this->awa->id}", $this->cle)->json('historique');
        $ligne = collect($historique)->firstWhere('par', 'Console · Ismaila');
        $this->assertSame(['essai', $fin, null], [$ligne['type'], $ligne['fin'], $ligne['recu_numero']]);
        $this->assertSame('Essai prolongé jusqu’au '.today()->addDays(40)->format('d/m/Y').' · Découvre encore', $ligne['note']);
    }

    public function test_un_essai_fini_se_prolonge_mais_pas_une_formule(): void
    {
        Abonnement::where('user_id', $this->awa->id)->update(['fin' => today()->subDays(3)->toDateString()]);
        $this->postJson("/api/console/v1/comptes/{$this->awa->id}/essai", ['fin' => today()->subDay()->toDateString()], $this->cle)->assertUnprocessable();
        $this->postJson("/api/console/v1/comptes/{$this->awa->id}/essai", ['fin' => today()->addWeek()->toDateString()], $this->cle)->assertOk()
            ->assertJsonPath('compte.en_cours', true);

        $this->postJson("/api/console/v1/comptes/{$this->awa->id}/abonnement", ['formule' => 'pro', 'fin' => today()->addMonth()->toDateString()], $this->cle)->assertOk();
        $this->assertFalse($this->getJson("/api/console/v1/comptes/{$this->awa->id}", $this->cle)->json('compte.essai_prolongeable'));
        $this->postJson("/api/console/v1/comptes/{$this->awa->id}/essai", ['fin' => today()->addWeek()->toDateString()], $this->cle)->assertUnprocessable();
        $this->assertSame('pro', Abonnement::where('user_id', $this->awa->id)->value('plan'));
    }
}
