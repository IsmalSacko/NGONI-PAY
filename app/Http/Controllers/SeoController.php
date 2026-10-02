<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Response;

/**
 * Référencement en pilote automatique : le plan du site et les consignes aux
 * robots se construisent à partir des routes publiques. Une page publique
 * ajoutée ici apparaît aux moteurs sans fichier à mettre à jour ; les espaces
 * privés (back-office, console) leur sont fermés.
 */
class SeoController extends Controller
{
    /** Pages publiques : route, priorité, fréquence de changement. */
    private const PAGES = [
        ['vitrine', '1.0', 'weekly'],
        ['telecharger', '0.8', 'monthly'],
        ['confidentialite', '0.3', 'yearly'],
        ['conditions', '0.3', 'yearly'],
        ['mentions-legales', '0.2', 'yearly'],
    ];

    public function sitemap(): Response
    {
        // La vitrine change avec les tarifs : sa date suit le dernier plan modifié.
        $tarifs = Plan::max('updated_at');

        $pages = collect(self::PAGES)->map(fn (array $p) => [
            'loc' => route($p[0]),
            'lastmod' => $p[0] === 'vitrine' && $tarifs ? \Illuminate\Support\Carbon::parse($tarifs)->toDateString() : $this->dateVue($p[0]),
            'priority' => $p[1],
            'changefreq' => $p[2],
        ]);

        return response()
            ->view('seo.sitemap', ['pages' => $pages])
            ->header('Content-Type', 'application/xml; charset=utf-8');
    }

    public function robots(): Response
    {
        $prives = ['/plateforme', '/tableau-de-bord', '/produits', '/categories', '/stocks', '/achats', '/ventes',
            '/rapports', '/clients', '/utilisateurs', '/boutiques', '/exports', '/connexion', '/mot-de-passe-oublie', '/api/'];

        $lignes = ['User-agent: *', 'Allow: /'];
        foreach ($prives as $chemin) {
            $lignes[] = 'Disallow: '.$chemin;
        }
        $lignes[] = '';
        $lignes[] = 'Sitemap: '.route('sitemap');

        return response(implode("\n", $lignes)."\n")->header('Content-Type', 'text/plain; charset=utf-8');
    }

    private function dateVue(string $route): string
    {
        $vue = ['telecharger' => 'telecharger', 'confidentialite' => 'juridique/confidentialite', 'conditions' => 'juridique/conditions', 'mentions-legales' => 'juridique/mentions'][$route] ?? 'vitrine';
        $fichier = resource_path("views/{$vue}.blade.php");

        return date('Y-m-d', is_file($fichier) ? filemtime($fichier) : time());
    }
}
