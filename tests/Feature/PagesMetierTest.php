<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\PagesMetier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pages de la vitrine par métier et par pays : ce que les commerçants
 * cherchent dans Google, reliées depuis l'accueil et dans le plan du site.
 */
class PagesMetierTest extends TestCase
{
    use RefreshDatabase;

    public function test_chaque_page_existe_avec_son_titre_et_ses_donnees_structurees(): void
    {
        foreach (PagesMetier::toutes() as $page) {
            $reponse = $this->get(route('page-metier', array_filter([$page['metier'], $page['pays']])))->assertOk();
            $reponse->assertSee(PagesMetier::METIERS[$page['metier']]['titre'], false)
                ->assertSee('application/ld+json', false)
                ->assertSee('rel="canonical"', false);
            if ($page['pays'] !== null) {
                $reponse->assertSee(PagesMetier::PAYS[$page['pays']]['ville'], false);
            }
        }
    }

    public function test_un_metier_ou_un_pays_inconnu_rend_404(): void
    {
        $this->get('/caisse/boulangerie')->assertNotFound();
        $this->get('/caisse/pharmacie/france')->assertNotFound();
    }

    public function test_le_plan_du_site_et_l_accueil_menent_aux_pages(): void
    {
        $plan = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertSame(5 + count(PagesMetier::toutes()), substr_count($plan, '<loc>'));
        $this->assertStringContainsString(route('page-metier', ['pharmacie', 'senegal']), $plan);

        $this->get('/')->assertOk()->assertSee(route('page-metier', ['restaurant', 'cameroun']), false);
    }
}
