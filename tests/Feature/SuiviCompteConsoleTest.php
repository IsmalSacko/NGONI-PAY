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

        $this->assertSame(20000, $i['ca_30j']['valeur'], 'la vente annulée ne compte pas');
        $this->assertSame(8000, $i['ca_30j']['avant']);
        $this->assertSame(3, $i['ventes_30j']['valeur']);
        $this->assertSame(1, $i['ventes_30j']['avant']);
        $this->assertSame(6667, $i['panier_moyen']['valeur']);
        $this->assertSame(28000, $i['ca_total']['valeur']);
        $this->assertSame(today()->toDateString(), $i['derniere_vente']['valeur']);
        $this->assertSame('Android · TECNO CM5', $i['derniere_visite']['note']);
        $this->assertSame('XOF', $i['ca_30j']['devise']);

        $this->assertCount(30, $suivi['courbe']['points']);
        $this->assertSame(['date' => today()->toDateString(), 'valeur' => 10000, 'ventes' => 1], end($suivi['courbe']['points']));
        $this->assertSame(10000, $suivi['courbe']['points'][26]['valeur']);

        $articles = $suivi['tableaux'][0];
        $this->assertSame('Meilleurs articles (30 jours)', $articles['titre']);
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
}
