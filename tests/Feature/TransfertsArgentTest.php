<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\OperateurTransfert;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le transfert d'argent : une cabine Orange Money, Moov Money, Wave… note ses
 * opérations ; l'application tient le solde de chaque puce et du tiroir, et
 * l'arrêté du soir compte les deux. Le client n'a pas l'application.
 */
class TransfertsArgentTest extends TestCase
{
    use RefreshDatabase;

    private User $moussa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ['user' => $this->moussa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Cabine Moussa', 'pays' => 'ML', 'telephone' => '76001122', 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => 'Moussa',
        ]);
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($this->moussa->createToken('t')->plainTextToken);
    }

    /** @return array<string, string> nom => id */
    private function demarrer(): array
    {
        $this->api()->postJson('/api/boutique/demarrage', ['activite' => 'transfert'])->assertOk();

        return collect($this->api()->getJson('/api/transferts')->assertOk()->json('data.operateurs'))->pluck('id', 'nom')->all();
    }

    /** @return array{especes: int, puces: array<string, int>} */
    private function soldes(): array
    {
        $etat = $this->api()->getJson('/api/transferts')->json('data');

        return ['especes' => $etat['especes'], 'puces' => collect($etat['operateurs'])->pluck('solde', 'nom')->all()];
    }

    public function test_une_boutique_qui_ne_fait_pas_de_transfert_n_y_a_pas_acces(): void
    {
        $this->api()->getJson('/api/transferts')->assertForbidden()->assertJsonPath('code', 'ACTIVITE_REQUISE');
        $this->api()->postJson('/api/transferts/operations', ['type' => 'depot', 'montant' => 1000])->assertForbidden();
    }

    public function test_au_demarrage_les_operateurs_du_pays_et_des_soldes_a_compter(): void
    {
        $operateurs = $this->demarrer();

        $this->assertSame(['Orange Money', 'Moov Money', 'Wave'], array_keys($operateurs));
        $this->api()->getJson('/api/transferts')->assertJsonPath('data.a_demarrer', true)->assertJsonPath('data.especes', 0);

        // Repasser en transfert ne recrée rien.
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'commerce'])->assertOk();
        $this->api()->putJson('/api/boutique/activite', ['activite' => 'transfert'])->assertOk();
        $this->assertSame(3, OperateurTransfert::withoutBoutiqueScope()->where('boutique_id', $this->boutique->id)->count());
    }

    public function test_chaque_operation_change_la_puce_et_le_tiroir_dans_le_bon_sens(): void
    {
        $op = $this->demarrer();

        // Les soldes de départ, comptés.
        $this->api()->postJson('/api/transferts/arrete', ['especes' => 50_000, 'puces' => [$op['Orange Money'] => 100_000]])->assertCreated();
        $this->assertSame(['especes' => 50_000, 'puces' => ['Orange Money' => 100_000, 'Moov Money' => 0, 'Wave' => 0]], $this->soldes());

        // Dépôt : le client donne 10 000 en espèces, la puce en envoie 10 000. Commission versée par l'opérateur.
        $this->api()->postJson('/api/transferts/operations', [
            'type' => 'depot', 'operateur_id' => $op['Orange Money'], 'montant' => 10_000, 'commission' => 100,
            'client_nom' => 'Awa', 'client_telephone' => '76 00 82 01', 'reference' => 'CI251030.1234.A1',
        ])->assertCreated()->assertJsonPath('data.effet_puce', -10_000)->assertJsonPath('data.effet_especes', 10_000);

        // Retrait Wave : la puce reçoit, le tiroir donne ; les 50 F de frais payés en espèces entrent au tiroir.
        $this->api()->postJson('/api/transferts/operations', [
            'type' => 'retrait', 'operateur_id' => $op['Wave'], 'montant' => 5_000, 'commission' => 50, 'commission_especes' => true,
        ])->assertCreated();

        // Envoi vers un autre numéro, facture, crédit : comme un dépôt.
        $this->api()->postJson('/api/transferts/operations', ['type' => 'envoi', 'operateur_id' => $op['Orange Money'], 'montant' => 20_000, 'beneficiaire' => '+223 70 11 22 33'])->assertCreated();
        $this->api()->postJson('/api/transferts/operations', ['type' => 'paiement', 'operateur_id' => $op['Orange Money'], 'montant' => 7_500, 'beneficiaire' => 'EDM 123456'])->assertCreated();
        $this->api()->postJson('/api/transferts/operations', ['type' => 'credit', 'operateur_id' => $op['Moov Money'], 'montant' => 1_000])->assertCreated();

        $this->assertSame([
            'especes' => 50_000 + 10_000 - 5_000 + 50 + 20_000 + 7_500 + 1_000,
            'puces' => ['Orange Money' => 100_000 - 10_000 - 20_000 - 7_500, 'Moov Money' => -1_000, 'Wave' => 5_000],
        ], $this->soldes());

        // Les fonds du commerçant : recharger Moov avec l'argent du tiroir, vider Wave sans passer par le tiroir,
        // mettre et prendre des espèces.
        $this->api()->postJson('/api/transferts/operations', ['type' => 'approvisionnement', 'operateur_id' => $op['Moov Money'], 'montant' => 30_000])->assertCreated();
        $this->api()->postJson('/api/transferts/operations', ['type' => 'vidage', 'operateur_id' => $op['Wave'], 'montant' => 5_000, 'via_tiroir' => false])->assertCreated();
        $this->api()->postJson('/api/transferts/operations', ['type' => 'apport_especes', 'montant' => 25_000])->assertCreated();
        $this->api()->postJson('/api/transferts/operations', ['type' => 'retrait_especes', 'montant' => 40_000, 'note' => 'Versé à la banque'])->assertCreated();

        $this->assertSame([
            'especes' => 83_550 - 30_000 + 25_000 - 40_000,
            'puces' => ['Orange Money' => 62_500, 'Moov Money' => 29_000, 'Wave' => 0],
        ], $this->soldes());

        // Opération d'un client sans opérateur, ou montant vide : refusée.
        $this->api()->postJson('/api/transferts/operations', ['type' => 'depot', 'montant' => 1_000])->assertUnprocessable()->assertJsonValidationErrors('operateur_id');
        $this->api()->postJson('/api/transferts/operations', ['type' => 'depot', 'operateur_id' => $op['Wave'], 'montant' => 0])->assertUnprocessable();

        // La journée : 5 opérations de clients, 150 F de commissions.
        $this->api()->getJson('/api/transferts')->assertJsonPath('data.aujourdhui.nombre', 5)->assertJsonPath('data.aujourdhui.commission', 150);
    }

    public function test_corriger_et_annuler_suivent_les_soldes_jusqu_a_l_arrete(): void
    {
        $op = $this->demarrer();
        $id = $this->api()->postJson('/api/transferts/operations', ['type' => 'depot', 'operateur_id' => $op['Orange Money'], 'montant' => 10_000])->json('data.id');

        // Mal tapé : 1 000 et non 10 000, et c'était Wave.
        $this->api()->putJson("/api/transferts/operations/{$id}", ['type' => 'depot', 'operateur_id' => $op['Wave'], 'montant' => 1_000])
            ->assertOk()->assertJsonPath('data.operateur_nom', 'Wave')->assertJsonPath('data.modifiable', true);
        $this->assertSame(['especes' => 1_000, 'puces' => ['Orange Money' => 0, 'Moov Money' => 0, 'Wave' => -1_000]], $this->soldes());

        // Annulée : barrée dans l'historique, sortie des soldes, plus modifiable.
        $this->api()->postJson("/api/transferts/operations/{$id}/annuler")->assertOk()->assertJsonPath('data.annulee_par', 'Moussa');
        $this->assertSame(0, $this->soldes()['especes']);
        $this->api()->putJson("/api/transferts/operations/{$id}", ['type' => 'depot', 'operateur_id' => $op['Wave'], 'montant' => 2_000])->assertUnprocessable();
        $this->api()->getJson('/api/transferts/operations')->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.modifiable', false);

        // Après l'arrêté, l'opération comptée ne se modifie plus.
        $compte = $this->api()->postJson('/api/transferts/operations', ['type' => 'retrait', 'operateur_id' => $op['Wave'], 'montant' => 3_000])->json('data.id');
        $this->api()->postJson('/api/transferts/arrete', ['especes' => 0, 'puces' => []])->assertCreated();
        $this->api()->putJson("/api/transferts/operations/{$compte}", ['type' => 'retrait', 'operateur_id' => $op['Wave'], 'montant' => 300])
            ->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->api()->postJson("/api/transferts/operations/{$compte}/annuler")->assertUnprocessable();
    }

    public function test_l_arrete_garde_le_compte_et_les_ecarts_deviennent_des_ajustements(): void
    {
        $op = $this->demarrer();
        $this->api()->postJson('/api/transferts/arrete', ['especes' => 20_000, 'puces' => [$op['Orange Money'] => 50_000, $op['Wave'] => 10_000]])->assertCreated();
        $this->api()->postJson('/api/transferts/operations', ['type' => 'depot', 'operateur_id' => $op['Orange Money'], 'montant' => 5_000])->assertCreated();

        // Le soir : il manque 500 F au tiroir, et Orange a 200 F de plus que prévu.
        $arrete = $this->api()->postJson('/api/transferts/arrete', ['especes' => 24_500, 'puces' => [$op['Orange Money'] => 45_200]])
            ->assertCreated()
            ->assertJsonPath('data.especes_theorique', 25_000)
            ->assertJsonPath('data.especes_compte', 24_500)
            ->json('data');
        $orange = collect($arrete['puces'])->firstWhere('nom', 'Orange Money');
        $this->assertSame(['theorique' => 45_000, 'compte' => 45_200], ['theorique' => $orange['theorique'], 'compte' => $orange['compte']]);
        // Wave n'a pas été recompté : il garde son solde.
        $this->assertSame(10_000, collect($arrete['puces'])->firstWhere('nom', 'Wave')['compte']);

        $this->assertSame(['especes' => 24_500, 'puces' => ['Orange Money' => 45_200, 'Moov Money' => 0, 'Wave' => 10_000]], $this->soldes());
        $this->api()->getJson('/api/transferts/operations?type=ajustement')->assertJsonCount(5, 'data');
        // Les opérations qui ont fait bouger les espèces : les deux comptes du tiroir et le dépôt.
        $this->api()->getJson('/api/transferts/operations?especes=1')->assertJsonCount(3, 'data');
        // Les premiers comptes sont les soldes de départ ; les suivants, des écarts.
        $notes = collect($this->api()->getJson('/api/transferts/operations?type=ajustement')->json('data'))->pluck('note')->countBy()->all();
        $this->assertSame(['Écart trouvé en faisant les comptes' => 2, 'Solde de départ' => 3], $notes);
        $this->api()->getJson('/api/transferts/arretes')->assertJsonCount(2, 'data')->assertJsonPath('data.0.par', 'Moussa');
        $this->api()->getJson('/api/transferts')->assertJsonPath('data.dernier_arrete.especes_compte', 24_500)->assertJsonPath('data.a_demarrer', false);
    }

    public function test_les_operateurs_s_ajoutent_se_renomment_et_ne_se_retirent_qu_a_zero(): void
    {
        $op = $this->demarrer();

        // Un opérateur de plus, avec ce qu'il y a déjà sur sa puce.
        $sama = $this->api()->postJson('/api/transferts/operateurs', ['nom' => ' Sama Money ', 'solde' => 15_000])
            ->assertCreated()->assertJsonPath('data.nom', 'Sama Money')->json('data.id');
        $this->assertSame(15_000, $this->soldes()['puces']['Sama Money']);
        $this->api()->postJson('/api/transferts/operateurs', ['nom' => 'Wave'])->assertUnprocessable()->assertJsonValidationErrors('nom');

        // Renommé : les opérations passées gardent leur nom d'alors.
        $this->api()->postJson('/api/transferts/operations', ['type' => 'depot', 'operateur_id' => $op['Moov Money'], 'montant' => 2_000])->assertCreated();
        $this->api()->putJson("/api/transferts/operateurs/{$op['Moov Money']}", ['nom' => 'Moov Africa'])->assertOk();
        $this->api()->getJson('/api/transferts/operations?type=depot')->assertJsonPath('data.0.operateur_nom', 'Moov Money');

        // Pas retiré tant que sa puce a de l'argent ; à zéro, oui, et ses opérations restent.
        $this->api()->deleteJson("/api/transferts/operateurs/{$sama}")->assertUnprocessable()->assertJsonValidationErrors('operateur');
        $this->api()->postJson('/api/transferts/operations', ['type' => 'vidage', 'operateur_id' => $sama, 'montant' => 15_000])->assertCreated();
        $this->api()->deleteJson("/api/transferts/operateurs/{$sama}")->assertOk();
        $this->assertArrayNotHasKey('Sama Money', $this->soldes()['puces']);
        $this->api()->getJson('/api/transferts/operations?operateur_id='.$sama)->assertJsonCount(2, 'data');
    }

    public function test_on_retrouve_un_client_par_son_numero_et_le_rapport_compte_les_commissions(): void
    {
        $op = $this->demarrer();
        $this->api()->postJson('/api/transferts/operations', ['type' => 'depot', 'operateur_id' => $op['Orange Money'], 'montant' => 10_000, 'commission' => 100, 'client_telephone' => '76 00 82 01'])->assertCreated();
        $this->api()->postJson('/api/transferts/operations', ['type' => 'retrait', 'operateur_id' => $op['Orange Money'], 'montant' => 4_000, 'commission' => 60, 'commission_especes' => true])->assertCreated();
        $this->api()->postJson('/api/transferts/operations', ['type' => 'depot', 'operateur_id' => $op['Wave'], 'montant' => 3_000, 'commission' => 30, 'client_nom' => 'Fanta'])->assertCreated();
        $annulee = $this->api()->postJson('/api/transferts/operations', ['type' => 'depot', 'operateur_id' => $op['Wave'], 'montant' => 9_000, 'commission' => 90])->json('data.id');
        $this->api()->postJson("/api/transferts/operations/{$annulee}/annuler")->assertOk();

        // Un client qui revient : quelques chiffres de son numéro, ou son nom, le retrouvent.
        $this->api()->postJson('/api/transferts/operations', ['type' => 'retrait', 'operateur_id' => $op['Wave'], 'montant' => 2_000, 'client_nom' => 'Awa Traoré', 'client_telephone' => '76008201'])->assertCreated();
        $this->api()->getJson('/api/transferts/clients?recherche=7600')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.telephone', '76008201')
            ->assertJsonPath('data.0.nom', 'Awa Traoré')
            ->assertJsonPath('data.0.nombre', 2);
        $this->api()->getJson('/api/transferts/clients?recherche=awa')->assertJsonCount(1, 'data');
        $this->api()->getJson('/api/transferts/clients?recherche=9')->assertUnprocessable();

        // Le numéro tapé sans espaces retrouve celui noté avec.
        $this->api()->getJson('/api/transferts/operations?recherche=76008201')->assertJsonCount(2, 'data')->assertJsonPath('data.1.montant', 10_000);
        $this->api()->getJson('/api/transferts/operations?recherche=fanta')->assertJsonCount(1, 'data');

        $jour = today()->toDateString();
        $rapport = $this->api()->getJson("/api/transferts/rapport?du={$jour}&au={$jour}")->assertOk()
            ->assertJsonPath('data.nombre', 4)
            ->assertJsonPath('data.commission', 190)
            ->assertJsonPath('data.commission_especes', 60)
            ->assertJsonPath('data.par_type.depot.montant', 13_000)
            ->json('data.par_operateur');
        $this->assertSame(['Orange Money', 'Wave'], array_column($rapport, 'nom'));
        $this->assertSame(['nombre' => 1, 'montant' => 4_000], $rapport[0]['types']['retrait']);
        $this->assertSame(['nombre' => 1, 'montant' => 2_000], $rapport[1]['types']['retrait']);
    }

    public function test_essai_termine_tout_se_consulte_plus_rien_ne_se_note(): void
    {
        $op = $this->demarrer();
        $id = $this->api()->postJson('/api/transferts/operations', ['type' => 'depot', 'operateur_id' => $op['Wave'], 'montant' => 1_000])->json('data.id');

        // Deux mois plus tard, l'essai est fini : une cabine ne transfère pas gratuitement pour toujours.
        $this->travel(60)->days();
        $this->api()->getJson('/api/transferts')->assertOk();
        $this->api()->getJson('/api/transferts/operations')->assertOk()->assertJsonCount(1, 'data');
        $jour = today()->toDateString();
        $this->api()->getJson("/api/transferts/rapport?du={$jour}&au={$jour}")->assertOk();

        foreach ([
            fn () => $this->api()->postJson('/api/transferts/operations', ['type' => 'depot', 'operateur_id' => $op['Wave'], 'montant' => 1_000]),
            fn () => $this->api()->putJson("/api/transferts/operations/{$id}", ['type' => 'depot', 'operateur_id' => $op['Wave'], 'montant' => 2_000]),
            fn () => $this->api()->postJson("/api/transferts/operations/{$id}/annuler"),
            fn () => $this->api()->postJson('/api/transferts/arrete', ['especes' => 0, 'puces' => []]),
            fn () => $this->api()->postJson('/api/transferts/operateurs', ['nom' => 'Sama Money']),
            fn () => $this->api()->putJson("/api/transferts/operateurs/{$op['Wave']}", ['nom' => 'Wave Mali']),
        ] as $ecriture) {
            $ecriture()->assertForbidden()->assertJsonPath('code', 'ABONNEMENT_EXPIRE');
        }
    }

    public function test_basic_note_et_compte_pro_a_le_rapport_et_l_historique(): void
    {
        $op = $this->demarrer();
        $jour = today()->toDateString();

        // Basic : noter, voir les soldes, faire ses comptes ; pas le rapport ni l'historique des comptes.
        app(AbonnementService::class)->accorder($this->moussa, 'basic', now()->addMonth(), $this->moussa);
        $this->api()->postJson('/api/transferts/operations', ['type' => 'depot', 'operateur_id' => $op['Wave'], 'montant' => 1_000, 'commission' => 50])->assertCreated();
        $this->api()->postJson('/api/transferts/arrete', ['especes' => 1_000, 'puces' => []])->assertCreated();
        $this->api()->getJson('/api/transferts')->assertOk()->assertJsonPath('data.aujourdhui.nombre', 1)->assertJsonPath('data.aujourdhui.commission', null);
        $this->api()->getJson("/api/transferts/rapport?du={$jour}&au={$jour}")->assertForbidden()->assertJsonPath('code', 'FONCTIONNALITE_NON_INCLUSE');
        $this->api()->getJson('/api/transferts/arretes')->assertForbidden();

        // Pro : tout.
        app(AbonnementService::class)->accorder($this->moussa, 'pro', now()->addMonth(), $this->moussa);
        $this->api()->getJson('/api/transferts')->assertJsonPath('data.aujourdhui.commission', 50);
        $this->api()->getJson("/api/transferts/rapport?du={$jour}&au={$jour}")->assertOk()->assertJsonPath('data.commission', 50);
        $this->api()->getJson('/api/transferts/arretes')->assertOk()->assertJsonCount(1, 'data');
    }
}
