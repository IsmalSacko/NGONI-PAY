<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Achat;
use App\Models\Boutique;
use App\Models\Client;
use App\Models\LigneVente;
use App\Models\MouvementStock;
use App\Models\PaiementFournisseur;
use App\Models\Produit;
use App\Models\SessionCaisse;
use App\Models\Vente;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques d'une boutique pour son commerçant, au-delà du rapport
 * d'activité (voir Rapports) : ce qui aide à décider.
 *
 * - `comparaison` : la période face à la précédente de même durée — pour
 *   tous les plans, c'est la première question (« ça va mieux ? »).
 * - `analyse` : le reste, réservé au plan qui inclut
 *   Plan::STATISTIQUES_AVANCEES — quand venir (affluence), quoi vendre et à
 *   quel prix (produits, catégories, marge), quoi racheter ou écouler (stock),
 *   qui revient et qui doit (clients, crédit), qui vend et qui a des écarts
 *   (équipe), et ce que coûtent les achats.
 *
 * Toujours dans la boutique active (contexte tenant) ; bornes par journée
 * d'affaires, comme le rapport et le ticket Z.
 */
class Statistiques
{
    /** Au-delà, un article en stock qui ne se vend plus immobilise de l'argent. */
    public const JOURS_DORMANT = 30;

    /** Fenêtre de calcul de la vitesse de vente, pour les jours de stock restants. */
    public const JOURS_VITESSE = 30;

    /** Sous ce nombre de jours de stock, l'article est à racheter. */
    public const JOURS_COUVERTURE_MIN = 7;

    /**
     * Âge minimal retenu pour la vitesse d'un article récent : deux ventes le
     * jour de sa création ne doivent pas annoncer une rupture imminente.
     */
    public const JOURS_VITESSE_MIN = 3;

    public function __construct(private readonly Rapports $rapports) {}

    /**
     * @return array{du: string, au: string, precedente: array{du: string, au: string}, indicateurs: array<string, array{actuel: int|float|null, precedent: int|float|null, variation: ?int}>, par_jour: list<array{date: string, total: int, precedent: int}>}
     */
    public function comparaison(Carbon $du, Carbon $au): array
    {
        $jours = (int) $du->copy()->startOfDay()->diffInDays($au->copy()->startOfDay()) + 1;
        $avantAu = $du->copy()->subDay();
        $avantDu = $avantAu->copy()->subDays($jours - 1);

        $actuel = $this->rapports->periode($du, $au);
        $precedent = $this->rapports->periode($avantDu, $avantAu);

        $indicateurs = [];
        foreach ([
            'chiffre_affaires' => fn ($r) => $r['ventes']['total'],
            'tickets' => fn ($r) => $r['ventes']['nombre'],
            'panier_moyen' => fn ($r) => $r['ventes']['panier_moyen'],
            'articles' => fn ($r) => $r['ventes']['articles'],
            'marge' => fn ($r) => $r['marge']['marge'],
            'taux_marge' => fn ($r) => $r['marge']['taux'],
        ] as $cle => $lire) {
            $a = $lire($actuel);
            $p = $lire($precedent);
            $indicateurs[$cle] = ['actuel' => $a, 'precedent' => $p, 'variation' => self::variation($a, $p)];
        }

        // Jour à jour : le n-ième jour de la période face au n-ième de la précédente.
        $totaux = collect($actuel['par_jour'])->pluck('total', 'date');
        $totauxAvant = collect($precedent['par_jour'])->pluck('total', 'date');
        $parJour = [];
        for ($i = 0; $i < $jours; $i++) {
            $jour = $du->copy()->addDays($i)->toDateString();
            $jourAvant = $avantDu->copy()->addDays($i)->toDateString();
            $parJour[] = ['date' => $jour, 'total' => (int) ($totaux[$jour] ?? 0), 'precedent' => (int) ($totauxAvant[$jourAvant] ?? 0)];
        }

        return [
            'du' => $du->toDateString(),
            'au' => $au->toDateString(),
            'precedente' => ['du' => $avantDu->toDateString(), 'au' => $avantAu->toDateString()],
            'indicateurs' => $indicateurs,
            'par_jour' => $parJour,
        ];
    }

    /** @return array<string, mixed> */
    public function analyse(Boutique $boutique, Carbon $du, Carbon $au): array
    {
        $ventes = fn () => Vente::valides()->whereBetween('ventes.jour_affaire', [$du->toDateString(), $au->toDateString()]);

        return [
            'affluence' => $this->affluence($ventes(), $boutique),
            'produits' => $this->produits($ventes()),
            'categories' => $this->categories($ventes()),
            'stock' => $this->stock($au, $boutique),
            'clients' => $this->clients($ventes(), $du),
            'equipe' => $this->equipe($du, $au),
            'achats' => $this->achats($du, $au),
        ];
    }

    /**
     * Tickets par jour de la semaine et par heure, à l'heure du pays de la
     * boutique (les dates sont enregistrées en UTC) : quand renforcer la
     * caisse, quand faire l'inventaire.
     *
     * @param  Builder<Vente>  $ventes
     * @return array{fuseau: string, grille: list<list<int>>, heure_pointe: ?int, jour_pointe: ?int}
     */
    private function affluence(Builder $ventes, Boutique $boutique): array
    {
        $fuseau = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, (string) $boutique->pays)[0] ?? 'UTC';

        // Grille [jour ISO 1 (lundi) … 7][heure 0 … 23], lue en PHP : l'heure
        // locale dépend du fuseau, que SQLite et MySQL ne traitent pas pareil.
        $grille = array_fill(0, 7, array_fill(0, 24, 0));
        foreach ($ventes->toBase()->select('ventes.created_at')->cursor() as $vente) {
            $le = CarbonImmutable::parse($vente->created_at, 'UTC')->setTimezone($fuseau);
            $grille[$le->dayOfWeekIso - 1][$le->hour]++;
        }

        $parHeure = array_map(fn (int $h) => array_sum(array_column($grille, $h)), range(0, 23));
        $parJour = array_map('array_sum', $grille);

        return [
            'fuseau' => $fuseau,
            'grille' => $grille,
            'heure_pointe' => max($parHeure) > 0 ? array_search(max($parHeure), $parHeure, true) : null,
            'jour_pointe' => max($parJour) > 0 ? array_search(max($parJour), $parJour, true) + 1 : null,
        ];
    }

    /**
     * Par article (et non par nom : un article renommé reste un seul article),
     * avec sa marge. Les montants libres, sans article, sont à part.
     *
     * @param  Builder<Vente>  $ventes
     * @return array{meilleurs: list<array<string, mixed>>, plus_rentables: list<array<string, mixed>>, a_surveiller: list<array<string, mixed>>, libres: array{nombre: int, total: int}}
     */
    private function produits(Builder $ventes): array
    {
        $lignes = LigneVente::query()->from('lignes_vente as lv')
            ->leftJoin('produits as p', 'p.id', '=', 'lv.produit_id')
            ->whereIn('lv.vente_id', (clone $ventes)->select('ventes.id'))
            ->whereNotNull('lv.produit_id')
            ->groupBy('lv.produit_id')
            ->get([
                'lv.produit_id',
                DB::raw('COALESCE(MAX(p.nom), MAX(lv.nom_produit)) as nom'),
                DB::raw('SUM(lv.quantite) as quantite'),
                DB::raw('SUM(lv.total_ligne) as total'),
                DB::raw('SUM(CASE WHEN COALESCE(lv.prix_achat, p.prix_achat) IS NOT NULL THEN lv.total_ligne END) as couvert'),
                DB::raw('SUM(COALESCE(lv.prix_achat, p.prix_achat) * lv.quantite) as cout'),
            ])
            ->map(function ($l) {
                $couvert = $l->couvert === null ? null : (int) $l->couvert;
                $marge = $couvert === null ? null : $couvert - (int) $l->cout;

                return [
                    'produit_id' => (string) $l->produit_id,
                    'nom' => (string) $l->nom,
                    'quantite' => (int) $l->quantite,
                    'total' => (int) $l->total,
                    'marge' => $marge,
                    'taux_marge' => $couvert ? round($marge * 100 / $couvert, 1) : null,
                ];
            });

        $libres = LigneVente::query()->whereIn('vente_id', (clone $ventes)->select('ventes.id'))->whereNull('produit_id');

        return [
            'meilleurs' => $lignes->sortByDesc('total')->take(10)->values()->all(),
            'plus_rentables' => $lignes->whereNotNull('marge')->sortByDesc('marge')->take(5)->values()->all(),
            // Vendus à perte ou presque : un prix à revoir, ou un prix d'achat mal saisi.
            'a_surveiller' => $lignes->filter(fn ($l) => $l['taux_marge'] !== null && $l['taux_marge'] < 10)->sortBy('taux_marge')->take(5)->values()->all(),
            'libres' => ['nombre' => (clone $libres)->count(), 'total' => (int) (clone $libres)->sum('total_ligne')],
        ];
    }

    /**
     * @param  Builder<Vente>  $ventes
     * @return list<array{nom: string, couleur: ?string, total: int, marge: ?int}>
     */
    private function categories(Builder $ventes): array
    {
        return LigneVente::query()->from('lignes_vente as lv')
            ->leftJoin('produits as p', 'p.id', '=', 'lv.produit_id')
            ->leftJoin('categories_produits as c', 'c.id', '=', 'p.categorie_produit_id')
            ->whereIn('lv.vente_id', (clone $ventes)->select('ventes.id'))
            ->groupBy('c.id', 'c.nom', 'c.couleur')
            ->orderByDesc(DB::raw('SUM(lv.total_ligne)'))
            ->get([
                'c.nom', 'c.couleur',
                DB::raw('SUM(lv.total_ligne) as total'),
                DB::raw('SUM(CASE WHEN COALESCE(lv.prix_achat, p.prix_achat) IS NOT NULL THEN lv.total_ligne END) as couvert'),
                DB::raw('SUM(COALESCE(lv.prix_achat, p.prix_achat) * lv.quantite) as cout'),
            ])
            ->map(fn ($c) => [
                'nom' => $c->nom ?? 'Sans catégorie',
                'couleur' => $c->couleur,
                'total' => (int) $c->total,
                'marge' => $c->couvert === null ? null : (int) $c->couvert - (int) $c->cout,
            ])->all();
    }

    /**
     * Ce qui dort (en stock, plus vendu depuis un mois : de l'argent
     * immobilisé) et ce qui va manquer (jours de stock au rythme récent).
     * Toujours à la date de fin, quelle que soit la période : le stock est
     * celui d'aujourd'hui.
     *
     * @return array{dormants: list<array<string, mixed>>, valeur_dormante: int, a_racheter: list<array<string, mixed>>}
     */
    private function stock(Carbon $au, Boutique $boutique): array
    {
        $vendus = fn (int $jours) => LigneVente::query()
            ->whereIn('vente_id', Vente::valides()->where('ventes.jour_affaire', '>', $au->copy()->subDays($jours)->toDateString())->select('ventes.id'))
            ->whereNotNull('produit_id')
            ->groupBy('produit_id')
            ->pluck(DB::raw('SUM(quantite)'), 'produit_id');

        $recents = $vendus(self::JOURS_VITESSE);
        $enStock = Produit::where('actif', true)->where('stock', '>', 0)->get(['id', 'nom', 'stock', 'prix_achat', 'prix_vente', 'seuil_alerte', 'created_at']);

        // Dernier retour en rayon après une rupture (stock passé de 0 à plus
        // de 0) : une marchandise arrivée hier n'a pas encore eu le temps de
        // ne pas se vendre. Un retour de vente annulée n'en est pas un.
        $retours = MouvementStock::query()
            ->whereNull('vente_id')
            ->where('quantite', '>', 0)
            ->whereRaw('stock_apres - quantite <= 0')
            ->groupBy('produit_id')
            ->pluck(DB::raw('MAX(created_at)'), 'produit_id');
        $enRayonDepuis = fn (Produit $p) => isset($retours[$p->id])
            ? $p->created_at->max(Carbon::parse($retours[$p->id]))
            : $p->created_at;
        $limiteDormant = $au->copy()->subDays(self::JOURS_DORMANT)->endOfDay();

        $dormants = $enStock
            ->reject(fn (Produit $p) => isset($recents[$p->id]) || $enRayonDepuis($p)->greaterThan($limiteDormant))
            ->map(fn (Produit $p) => [
                'produit_id' => $p->id,
                'nom' => $p->nom,
                'stock' => $p->stock,
                // Au prix d'achat quand il est connu : ce que l'article a coûté, pas ce qu'il rapporterait.
                'valeur' => $p->stock * ($p->prix_achat ?? $p->prix_vente),
                'valeur_estimee' => $p->prix_achat === null,
            ])
            ->sortByDesc('valeur')->values();

        $aRacheter = $enStock
            ->filter(fn (Produit $p) => isset($recents[$p->id]))
            ->map(function (Produit $p) use ($recents, $au) {
                // Sur l'âge réel d'un article de moins d'un mois : 30 ventes
                // en 3 jours, c'est 10 par jour, pas 1.
                $age = (int) $p->created_at->copy()->startOfDay()->diffInDays($au->copy()->startOfDay()) + 1;
                $parJour = (int) $recents[$p->id] / max(self::JOURS_VITESSE_MIN, min(self::JOURS_VITESSE, $age));

                return [
                    'produit_id' => $p->id,
                    'nom' => $p->nom,
                    'stock' => $p->stock,
                    'vendus_par_jour' => round($parJour, 1),
                    'jours_restants' => (int) floor($p->stock / $parJour),
                ];
            })
            ->filter(fn ($p) => $p['jours_restants'] < self::JOURS_COUVERTURE_MIN)
            ->sortBy('jours_restants')->values();

        return [
            'dormants' => $dormants->take(10)->all(),
            'valeur_dormante' => (int) $dormants->sum('valeur'),
            // Jours avant qu'une boutique neuve ait le recul d'un mois : d'ici
            // là, une liste vide ne veut pas dire que tout se vend.
            'moins_vendus_dans' => max(0, self::JOURS_DORMANT - (int) $boutique->created_at->copy()->startOfDay()->diffInDays($au->copy()->startOfDay())),
            'a_racheter' => $aRacheter->take(10)->all(),
        ];
    }

    /**
     * Qui achète, qui revient, qui doit.
     *
     * @param  Builder<Vente>  $ventes
     * @return array<string, mixed>
     */
    private function clients(Builder $ventes, Carbon $du): array
    {
        $parClient = (clone $ventes)->whereNotNull('ventes.client_id')
            ->groupBy('ventes.client_id')
            ->get(['ventes.client_id', DB::raw('COUNT(*) as visites'), DB::raw('SUM(ventes.total) as total')]);
        $total = (int) (clone $ventes)->sum('total');
        $identifies = (int) $parClient->sum('total');

        // Nouveau : dont le premier achat validé tombe dans la période.
        $nouveaux = Vente::valides()->whereIn('client_id', $parClient->pluck('client_id'))
            ->groupBy('client_id')
            ->havingRaw('MIN(jour_affaire) >= ?', [$du->toDateString()])
            ->pluck('client_id')->count();

        $noms = Client::whereIn('id', $parClient->pluck('client_id'))->pluck('nom', 'id');
        $debiteurs = Client::query()->avecSoldeDu()
            ->withMax('reglements as dernier_reglement', 'created_at')
            ->get(['id', 'nom', 'telephone'])
            ->map(fn (Client $c) => [
                'nom' => $c->nom,
                'telephone' => $c->telephone,
                'solde_du' => max(0, (int) $c->credit_total - (int) $c->reglements_total),
                'dernier_reglement' => $c->dernier_reglement ? Carbon::parse($c->dernier_reglement)->toDateString() : null,
            ])
            ->where('solde_du', '>', 0)
            ->sortByDesc('solde_du')->values();

        return [
            'actifs' => $parClient->count(),
            'nouveaux' => $nouveaux,
            'fideles' => $parClient->where('visites', '>=', 2)->count(),
            'part_identifiee' => $total > 0 ? (int) round($identifies * 100 / $total) : null,
            'meilleurs' => $parClient->sortByDesc('total')->take(5)->map(fn ($c) => [
                'nom' => (string) ($noms[$c->client_id] ?? 'Client supprimé'),
                'visites' => (int) $c->visites,
                'total' => (int) $c->total,
            ])->values()->all(),
            'credit' => [
                'encours' => (int) $debiteurs->sum('solde_du'),
                'debiteurs' => $debiteurs->count(),
                'plus_gros' => $debiteurs->take(5)->all(),
            ],
        ];
    }

    /**
     * Par membre de l'équipe : ce qu'il a encaissé, ce qu'il a annulé, et
     * les écarts de ses séances de caisse closes.
     *
     * @return list<array<string, mixed>>
     */
    private function equipe(Carbon $du, Carbon $au): array
    {
        $bornes = [$du->toDateString(), $au->toDateString()];
        $parStatut = Vente::query()->whereBetween('ventes.jour_affaire', $bornes)
            ->join('users', 'users.id', '=', 'ventes.user_id')
            ->groupBy('ventes.user_id', 'users.name')
            ->get([
                'ventes.user_id', 'users.name',
                DB::raw("SUM(CASE WHEN ventes.statut = 'validee' THEN 1 ELSE 0 END) as tickets"),
                DB::raw("SUM(CASE WHEN ventes.statut = 'validee' THEN ventes.total ELSE 0 END) as total"),
                DB::raw("SUM(CASE WHEN ventes.statut = 'annulee' THEN 1 ELSE 0 END) as annulations"),
            ]);

        $seances = SessionCaisse::query()->whereNotNull('fermee_le')
            ->whereBetween('fermee_le', [$du->copy()->startOfDay(), $au->copy()->endOfDay()])
            ->groupBy('user_id')
            ->get(['user_id', DB::raw('COUNT(*) as nombre'), DB::raw('SUM(ecart) as ecart'), DB::raw('SUM(CASE WHEN ecart < 0 THEN ecart ELSE 0 END) as manquant')])
            ->keyBy('user_id');

        return $parStatut->map(fn ($u) => [
            'nom' => (string) $u->name,
            'tickets' => (int) $u->tickets,
            'total' => (int) $u->total,
            'panier_moyen' => (int) $u->tickets > 0 ? (int) round($u->total / $u->tickets) : 0,
            'annulations' => (int) $u->annulations,
            'seances' => (int) ($seances[$u->user_id]->nombre ?? 0),
            'ecart' => isset($seances[$u->user_id]) ? (int) $seances[$u->user_id]->ecart : null,
            'manquant' => isset($seances[$u->user_id]) ? (int) $seances[$u->user_id]->manquant : null,
        ])->sortByDesc('total')->values()->all();
    }

    /** @return array{total: int, paye: int, du_fournisseurs: int} */
    private function achats(Carbon $du, Carbon $au): array
    {
        $bornes = [$du->copy()->startOfDay(), $au->copy()->endOfDay()];

        return [
            'total' => (int) Achat::whereBetween('created_at', $bornes)->sum('total'),
            'paye' => (int) PaiementFournisseur::whereBetween('created_at', $bornes)->sum('montant'),
            // Tout ce qui reste dû aux fournisseurs, quelle que soit la période.
            'du_fournisseurs' => max(0, (int) Achat::sum('total') - (int) PaiementFournisseur::sum('montant')),
        ];
    }

    private static function variation(int|float|null $actuel, int|float|null $precedent): ?int
    {
        return $actuel === null || ! $precedent ? null : (int) round(($actuel - $precedent) / abs($precedent) * 100);
    }
}
