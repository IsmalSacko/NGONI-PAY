<?php

declare(strict_types=1);

namespace App\Services\Console;

use App\Models\Boutique;
use App\Models\Produit;
use App\Models\User;
use App\Support\Console\PeriodeSuivi;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le suivi de l'activité d'un commerçant pour la Console Ngoni (contrats v1.5
 * et v1.6) : chiffre d'affaires et ventes de la période comparés à la période
 * d'avant, panier moyen, courbe, meilleurs articles, et ses boutiques.
 *
 * Mêmes règles que l'application : seules les ventes validées comptent (une
 * vente annulée ou remplacée n'est plus « validee »), rangées à leur journée de
 * vente (`jour_affaire`, celle du rapport et du ticket Z) ; l'heure est celle
 * de l'encaissement. Un pressing ne suit pas de stock : pas de rupture.
 */
class SuiviCompte
{
    private const JOUR = 'COALESCE(jour_affaire, DATE(created_at))';

    /**
     * @param  array{periode?: ?string, du?: ?string, au?: ?string}  $choix
     * @return array<string, mixed>|null
     */
    public function pour(User $u, array $choix = []): ?array
    {
        $boutiques = Boutique::withoutGlobalScopes()->where('proprietaire_id', $u->id)->orderBy('created_at')->get();
        if ($boutiques->isEmpty()) {
            return null;
        }
        // Les montants ne s'additionnent que dans une même devise : celle de sa première boutique.
        $devise = (string) ($boutiques->first()->devise ?: 'XOF');
        $memes = $boutiques->filter(fn (Boutique $b) => ($b->devise ?: 'XOF') === $devise);
        $ids = $memes->pluck('id')->map(fn ($id) => (string) $id)->all();
        $ventes = fn (): Builder => DB::table('ventes')->whereIn('boutique_id', $ids)->where('statut', 'validee');

        $premiere = $ventes()->min(DB::raw(self::JOUR));
        $p = PeriodeSuivi::depuis(
            $choix['periode'] ?? null, $choix['du'] ?? null, $choix['au'] ?? null,
            $premiere ? CarbonImmutable::parse($premiere) : CarbonImmutable::parse($memes->first()->created_at),
        );
        $entre = fn (CarbonImmutable $du, CarbonImmutable $au) => $ventes()->whereBetween(DB::raw(self::JOUR), [$du->toDateString(), $au->toDateString()]);

        $ca = (int) $entre($p->du, $p->au)->sum('total');
        $n = $entre($p->du, $p->au)->count();
        $avant = $p->avantDu === null ? null : ['ca' => (int) $entre($p->avantDu, $p->avantAu)->sum('total'), 'n' => $entre($p->avantDu, $p->avantAu)->count()];
        $derniere = $ventes()->max('created_at');

        $suivis = $memes->filter(fn (Boutique $b) => $b->suitLeStock())->pluck('id')->all();
        $rupture = $suivis === [] ? 0 : Produit::withoutGlobalScopes()->whereIn('boutique_id', $suivis)->whereNull('deleted_at')
            ->whereNull('tarifs')->where('actif', true)->where('stock', '<=', 0)->count();
        $montant = fn (string $cle, string $libelle, ?int $valeur, ?int $avantValeur = null) => ['cle' => $cle, 'libelle' => $libelle, 'type' => 'montant', 'valeur' => $valeur, 'devise' => $devise]
            + ($avantValeur === null ? [] : ['avant' => $avantValeur]);

        return [
            'periode' => $p->versTableau(),
            'indicateurs' => [
                $montant('ca', 'Chiffre d’affaires', $ca, $avant['ca'] ?? null),
                ['cle' => 'ventes', 'libelle' => 'Ventes', 'type' => 'nombre', 'valeur' => $n] + ($avant === null ? [] : ['avant' => $avant['n']]),
                $montant('panier_moyen', 'Panier moyen', $n > 0 ? (int) round($ca / $n) : null),
                $montant('ca_total', 'Chiffre d’affaires depuis le début', (int) $ventes()->sum('total')),
                ['cle' => 'derniere_vente', 'libelle' => 'Dernière vente', 'type' => 'date', 'valeur' => $derniere ? CarbonImmutable::parse($derniere)->toDateString() : null],
                ['cle' => 'articles', 'libelle' => 'Articles', 'type' => 'nombre', 'valeur' => Produit::withoutGlobalScopes()->whereIn('boutique_id', $ids)->whereNull('deleted_at')->count()]
                + ($rupture > 0 ? ['note' => $rupture.' en rupture'] : []),
                ['cle' => 'clients', 'libelle' => 'Clients', 'type' => 'nombre', 'valeur' => DB::table('clients')->whereIn('boutique_id', $ids)->whereNull('deleted_at')->count()],
                ['cle' => 'derniere_visite', 'libelle' => 'Dernière visite dans l’application', 'type' => 'instant', 'valeur' => $u->vu_le?->toIso8601String()]
                + (($note = $this->appareil($u)) !== '' ? ['note' => $note] : []),
            ],
            'courbe' => ['titre' => 'Chiffre d’affaires', 'devise' => $devise, 'points' => $this->courbe($entre($p->du, $p->au), $p)],
            'tableaux' => array_values(array_filter([
                $this->meilleursArticles($ids, $p, $devise),
                $boutiques->count() > 1 ? $this->sesBoutiques($boutiques, $p) : null,
            ])),
        ];
    }

    private function appareil(User $u): string
    {
        $plateforme = match ((string) $u->vu_plateforme) {
            'android' => 'Android', 'ios' => 'iPhone', 'web' => 'Navigateur', 'windows' => 'Windows', 'macos' => 'Mac', default => ucfirst((string) $u->vu_plateforme),
        };

        return implode(' · ', array_filter([$plateforme, (string) $u->vu_modele]));
    }

    /** @return list<array{date: string, libelle: string, valeur: int, ventes: int}> */
    private function courbe(Builder $ventes, PeriodeSuivi $p): array
    {
        $parCle = [];
        if ($p->pas === 'heure') {
            // Une seule journée : les ventes une à une, rangées à l'heure de l'encaissement.
            foreach ($ventes->get(['created_at', 'total']) as $v) {
                $h = (string) CarbonImmutable::parse($v->created_at)->hour;
                $parCle[$h]['ca'] = ($parCle[$h]['ca'] ?? 0) + (int) $v->total;
                $parCle[$h]['n'] = ($parCle[$h]['n'] ?? 0) + 1;
            }
        } else {
            $jours = $ventes->groupBy(DB::raw(self::JOUR))
                ->select(DB::raw(self::JOUR.' as jour'), DB::raw('SUM(total) as ca'), DB::raw('COUNT(*) as n'))->get();
            foreach ($jours as $r) {
                $cle = substr((string) $r->jour, 0, $p->pas === 'mois' ? 7 : 10);
                $parCle[$cle]['ca'] = ($parCle[$cle]['ca'] ?? 0) + (int) $r->ca;
                $parCle[$cle]['n'] = ($parCle[$cle]['n'] ?? 0) + (int) $r->n;
            }
        }

        return array_map(fn (array $c) => [
            'date' => $c['date'], 'libelle' => $c['libelle'],
            'valeur' => (int) ($parCle[$c['cle']]['ca'] ?? 0), 'ventes' => (int) ($parCle[$c['cle']]['n'] ?? 0),
        ], $p->creneaux());
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, mixed>|null
     */
    private function meilleursArticles(array $ids, PeriodeSuivi $p, string $devise): ?array
    {
        $lignes = DB::table('lignes_vente')->join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->whereIn('ventes.boutique_id', $ids)->where('ventes.statut', 'validee')
            ->whereBetween(DB::raw('COALESCE(ventes.jour_affaire, DATE(ventes.created_at))'), [$p->du->toDateString(), $p->au->toDateString()])
            ->groupBy('lignes_vente.nom_produit')
            ->select('lignes_vente.nom_produit', DB::raw('SUM(lignes_vente.quantite) as quantite'), DB::raw('SUM(lignes_vente.total_ligne) as ca'))
            ->orderByDesc('ca')->limit(10)->get();
        if ($lignes->isEmpty()) {
            return null;
        }

        return [
            'titre' => 'Meilleurs articles',
            'colonnes' => [['libelle' => 'Article', 'type' => 'texte'], ['libelle' => 'Quantité', 'type' => 'nombre'], ['libelle' => 'Chiffre d’affaires', 'type' => 'montant']],
            'devise' => $devise,
            'lignes' => $lignes->map(fn ($l) => [(string) $l->nom_produit, (int) round((float) $l->quantite), (int) $l->ca])->all(),
        ];
    }

    /**
     * @param  Collection<int, Boutique>  $boutiques
     * @return array<string, mixed>
     */
    private function sesBoutiques(Collection $boutiques, PeriodeSuivi $p): array
    {
        $dans = "COALESCE(jour_affaire, DATE(created_at)) BETWEEN '{$p->du->toDateString()}' AND '{$p->au->toDateString()}'";
        $stats = DB::table('ventes')->whereIn('boutique_id', $boutiques->pluck('id')->all())->where('statut', 'validee')->groupBy('boutique_id')
            ->select('boutique_id', DB::raw("SUM(CASE WHEN {$dans} THEN total ELSE 0 END) as ca"), DB::raw("SUM(CASE WHEN {$dans} THEN 1 ELSE 0 END) as n"), DB::raw('MAX(created_at) as derniere'))
            ->get()->keyBy('boutique_id');

        return [
            'titre' => 'Ses boutiques',
            'colonnes' => [['libelle' => 'Boutique', 'type' => 'texte'], ['libelle' => 'Chiffre d’affaires', 'type' => 'texte'], ['libelle' => 'Ventes', 'type' => 'nombre'], ['libelle' => 'Dernière vente', 'type' => 'date']],
            'lignes' => $boutiques->take(10)->map(function (Boutique $b) use ($stats) {
                $s = $stats[$b->id] ?? null;

                return [
                    (string) $b->nom,
                    number_format((int) ($s->ca ?? 0), 0, ',', ' ').' '.($b->devise ?: 'XOF'),
                    (int) ($s->n ?? 0),
                    $s?->derniere ? CarbonImmutable::parse($s->derniere)->toDateString() : null,
                ];
            })->values()->all(),
        ];
    }
}
