<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Contrat v1.5 : suivi de l'activité d'un compte dans la Console Ngoni. */
class SuiviCompteConsoleTest extends TestCase
{
    use RefreshDatabase;

    private array $cle = ['Authorization' => 'Bearer cle-de-test-console', 'X-Console-Operateur' => 'Ismaila'];

    private User $awa;

    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['services.console_ngoni.cle' => 'cle-de-test-console']);
        ['user' => $this->awa, 'boutique' => $this->boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Boutique Awa', 'pays' => 'ML', 'telephone' => '76008201', 'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        $this->awa->forceFill(['vu_le' => now(), 'vu_plateforme' => 'android', 'vu_modele' => 'TECNO CM5'])->save();
    }

    private function vente(int $total, string $jour, string $statut = 'validee', string $article = 'Sac'): void
    {
        app(TenantContext::class)->setBoutique($this->boutique->id);
        $v = Vente::factory()->create([
            'user_id' => $this->awa->id, 'sous_total' => $total, 'tva' => 0, 'total' => $total, 'moyen_paiement' => 'especes',
            'statut' => $statut, 'jour_affaire' => $jour, 'created_at' => $jour.' 10:00:00',
        ]);
        DB::table('lignes_vente')->insert(['id' => (string) str()->uuid(), 'vente_id' => $v->id, 'nom_produit' => $article, 'prix_unitaire' => $total, 'taux_tva' => 0, 'quantite' => 1, 'total_ligne' => $total, 'created_at' => now(), 'updated_at' => now()]);
        app(TenantContext::class)->forget();
    }

    public function test_la_fiche_donne_le_suivi_des_30_jours(): void
    {
        $this->vente(10000, today()->toDateString(), article: 'Sac');
        $this->vente(5000, today()->subDays(3)->toDateString(), article: 'Ceinture');
        $this->vente(5000, today()->subDays(3)->toDateString(), article: 'Sac');
        $this->vente(99000, today()->toDateString(), statut: 'annulee');
        $this->vente(8000, today()->subDays(40)->toDateString());

        $this->getJson('/api/console/v1/resume', $this->cle)->assertJsonFragment(['suivi']);
        $suivi = $this->getJson("/api/console/v1/comptes/{$this->awa->id}", $this->cle)->assertOk()->json('suivi');
        $i = collect($suivi['indicateurs'])->keyBy('cle');

        $this->assertSame(20000, $i['ca']['valeur'], 'la vente annulée ne compte pas');
        $this->assertSame(8000, $i['ca']['avant']);
        $this->assertSame(3, $i['ventes']['valeur']);
        $this->assertSame(1, $i['ventes']['avant']);
        $this->assertSame(6667, $i['panier_moyen']['valeur']);
        $this->assertSame(28000, $i['ca_total']['valeur']);
        $this->assertSame(today()->toDateString(), $i['derniere_vente']['valeur']);
        $this->assertSame('Android · TECNO CM5', $i['derniere_visite']['note']);
        $this->assertSame('XOF', $i['ca']['devise']);

        $this->assertCount(30, $suivi['courbe']['points']);
        $this->assertSame(['date' => today()->toDateString(), 'libelle' => today()->format('d/m'), 'valeur' => 10000, 'ventes' => 1], end($suivi['courbe']['points']));
        $this->assertSame(10000, $suivi['courbe']['points'][26]['valeur']);

        $articles = $suivi['tableaux'][0];
        $this->assertSame('Meilleurs articles', $articles['titre']);
        $this->assertSame([['Sac', 2, 15000], ['Ceinture', 1, 5000]], $articles['lignes']);
    }

    public function test_les_articles_en_rupture_sont_notes(): void
    {
        app(TenantContext::class)->setBoutique($this->boutique->id);
        $base = Produit::count();
        Produit::factory()->create(['stock' => 0, 'actif' => true]);
        Produit::factory()->create(['stock' => 5, 'actif' => true]);
        app(TenantContext::class)->forget();

        $i = collect($this->getJson("/api/console/v1/comptes/{$this->awa->id}", $this->cle)->json('suivi.indicateurs'))->keyBy('cle');
        $this->assertSame($base + 2, $i['articles']['valeur']);
        $this->assertStringContainsString('en rupture', $i['articles']['note']);
        $this->assertNull($i['panier_moyen']['valeur'], 'pas de vente : pas de panier moyen');
    }

    /** @return array<string, mixed> */
    private function suivi(string $requete): array
    {
        return $this->getJson("/api/console/v1/comptes/{$this->awa->id}?{$requete}", $this->cle)->assertOk()->json('suivi');
    }

    public function test_les_periodes_du_suivi(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(15, 0));
        $this->vente(10000, '2026-11-05');
        $this->vente(4000, '2026-11-04');
        $this->vente(3000, '2026-10-03');
        $this->vente(2000, '2026-10-20');
        $this->vente(1000, '2026-06-15');

        $jour = $this->suivi('periode=jour');
        $this->assertSame(['code' => 'jour', 'libelle' => 'Aujourd’hui', 'du' => '2026-11-05', 'au' => '2026-11-05', 'avant_du' => '2026-11-04', 'avant_au' => '2026-11-04'], $jour['periode']);
        $i = collect($jour['indicateurs'])->keyBy('cle');
        $this->assertSame([10000, 4000], [$i['ca']['valeur'], $i['ca']['avant']]);
        $this->assertCount(24, $jour['courbe']['points']);
        $this->assertSame(['date' => '2026-11-05T10:00', 'libelle' => '10 h', 'valeur' => 10000, 'ventes' => 1], $jour['courbe']['points'][10]);

        $sept = $this->suivi('periode=7j');
        $this->assertCount(7, $sept['courbe']['points']);
        $this->assertSame('30/10', $sept['courbe']['points'][0]['libelle']);
        $this->assertSame(14000, collect($sept['indicateurs'])->keyBy('cle')['ca']['valeur']);

        // Ce mois : du 1er au 5 novembre, comparé du 1er au 5 octobre.
        $mois = $this->suivi('periode=mois');
        $i = collect($mois['indicateurs'])->keyBy('cle');
        $this->assertSame([14000, 3000], [$i['ca']['valeur'], $i['ca']['avant']]);
        $this->assertSame(['2026-11-01', '2026-10-01', '2026-10-05'], [$mois['periode']['du'], $mois['periode']['avant_du'], $mois['periode']['avant_au']]);
        $this->assertCount(5, $mois['courbe']['points']);

        // Depuis le début : par mois, depuis la première vente, sans comparaison.
        $tout = $this->suivi('periode=tout');
        $i = collect($tout['indicateurs'])->keyBy('cle');
        $this->assertSame(20000, $i['ca']['valeur']);
        $this->assertArrayNotHasKey('avant', $i['ca']);
        $this->assertSame(['juin 2026', 'juil. 2026', 'août 2026', 'sept. 2026', 'oct. 2026', 'nov. 2026'], array_column($tout['courbe']['points'], 'libelle'));
        $this->assertSame(5000, $tout['courbe']['points'][4]['valeur']);

        // Du … au … : comparé à la même durée juste avant.
        $perso = $this->suivi('periode=perso&du=2026-10-15&au=2026-10-31');
        $this->assertSame(['Du 15/10/2026 au 31/10/2026', '2026-09-28', '2026-10-14'], [$perso['periode']['libelle'], $perso['periode']['avant_du'], $perso['periode']['avant_au']]);
        $i = collect($perso['indicateurs'])->keyBy('cle');
        $this->assertSame([2000, 3000], [$i['ca']['valeur'], $i['ca']['avant']]);
        $this->assertCount(17, $perso['courbe']['points']);
    }

    public function test_une_periode_invalide_est_refusee(): void
    {
        $url = "/api/console/v1/comptes/{$this->awa->id}?";
        $this->getJson($url.'periode=hier', $this->cle)->assertStatus(422)->assertJsonPath('message', 'Période inconnue : choisissez aujourd’hui, 7 jours, ce mois, depuis le début, ou des dates.');
        $this->getJson($url.'periode=perso&du=2026-10-31&au=2026-10-01', $this->cle)->assertStatus(422)->assertJsonPath('message', 'La date de début doit venir avant la date de fin.');
        $this->getJson($url.'periode=perso&du=2026-10-01&au='.now()->addDay()->toDateString(), $this->cle)->assertStatus(422);
        $this->getJson($url.'periode=perso&du=nimporte&au=2026-10-01', $this->cle)->assertStatus(422)->assertJsonPath('message', 'Choisissez une date de début valide.');
    }
}
