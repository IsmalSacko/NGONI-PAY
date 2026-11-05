<?php

declare(strict_types=1);

namespace App\Services\Console;

use App\Models\Boutique;
use App\Models\Produit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le suivi de l'activité d'un commerçant pour la Console Ngoni (contrat v1.5) :
 * chiffre d'affaires et ventes des 30 derniers jours comparés aux 30 d'avant,
 * panier moyen, courbe par jour, meilleurs articles, et ses boutiques.
 *
 * Mêmes règles que l'application : seules les ventes validées comptent (une
 * vente annulée ou remplacée n'est plus « validee »), rangées à leur journée de
 * vente (`jour_affaire`, celle du rapport et du ticket Z). Un pressing ne suit
 * pas de stock : pas de rupture.
 */
class SuiviCompte
{
    /** @return array<string, mixed>|null */
    public function pour(User $u): ?array
    {
        $boutiques = Boutique::withoutGlobalScopes()->where('proprietaire_id', $u->id)->orderBy('created_at')->get();
        if ($boutiques->isEmpty()) {
            return null;
        }
        // Les montants ne s'additionnent que dans une même devise : celle de sa première boutique.
        $devise = (string) ($boutiques->first()->devise ?: 'XOF');
        $memes = $boutiques->filter(fn (Boutique $b) => ($b->devise ?: 'XOF') === $devise);
        $ids = $memes->pluck('id')->map(fn ($id) => (string) $id)->all();

        $aujourdhui = CarbonImmutable::today();
        $debut = $aujourdhui->subDays(29);
        $avant = $debut->subDays(30);
        $jour = DB::raw('COALESCE(jour_affaire, DATE(created_at))');
        $ventes = fn (): Builder => DB::table('ventes')->whereIn('boutique_id', $ids)->where('statut', 'validee');
        $periode = fn (CarbonImmutable $du, CarbonImmutable $au) => $ventes()->whereBetween($jour, [$du->toDateString(), $au->toDateString()]);

        $ca30 = (int) $periode($debut, $aujourdhui)->sum('total');
        $n30 = $periode($debut, $aujourdhui)->count();
        $derniere = $ventes()->max('created_at');

        $produits = Produit::withoutGlobalScopes()->whereIn('boutique_id', $ids)->whereNull('deleted_at');
        $suivis = $memes->filter(fn (Boutique $b) => $b->suitLeStock())->pluck('id')->all();
        $rupture = $suivis === [] ? 0 : Produit::withoutGlobalScopes()->whereIn('boutique_id', $suivis)->whereNull('deleted_at')
            ->whereNull('tarifs')->where('actif', true)->where('stock', '<=', 0)->count();

        $montant = fn (string $cle, string $libelle, ?int $valeur, ?int $avantValeur = null) => ['cle' => $cle, 'libelle' => $libelle, 'type' => 'montant', 'valeur' => $valeur, 'devise' => $devise]
            + ($avantValeur === null ? [] : ['avant' => $avantValeur]);

        $indicateurs = [
            $montant('ca_30j', 'Chiffre d’affaires (30 jours)', $ca30, (int) $periode($avant, $debut->subDay())->sum('total')),
            ['cle' => 'ventes_30j', 'libelle' => 'Ventes (30 jours)', 'type' => 'nombre', 'valeur' => $n30, 'avant' => $periode($avant, $debut->subDay())->count()],
            $montant('panier_moyen', 'Panier moyen (30 jours)', $n30 > 0 ? (int) round($ca30 / $n30) : null),
            $montant('ca_total', 'Chiffre d’affaires depuis le début', (int) $ventes()->sum('total')),
            ['cle' => 'derniere_vente', 'libelle' => 'Dernière vente', 'type' => 'date', 'valeur' => $derniere ? CarbonImmutable::parse($derniere)->toDateString() : null],
            ['cle' => 'articles', 'libelle' => 'Articles', 'type' => 'nombre', 'valeur' => (clone $produits)->count()]
            + ($rupture > 0 ? ['note' => $rupture.' en rupture'] : []),
            ['cle' => 'clients', 'libelle' => 'Clients', 'type' => 'nombre', 'valeur' => DB::table('clients')->whereIn('boutique_id', $ids)->whereNull('deleted_at')->count()],
            ['cle' => 'derniere_visite', 'libelle' => 'Dernière visite dans l’application', 'type' => 'instant', 'valeur' => $u->vu_le?->toIso8601String()]
            + (($note = $this->appareil($u)) !== '' ? ['note' => $note] : []),
        ];

        return [
            'indicateurs' => $indicateurs,
            'courbe' => $this->courbe($ventes, $jour, $debut, $aujourdhui, $devise),
            'tableaux' => array_values(array_filter([
                $this->meilleursArticles($ids, $debut, $devise),
                $boutiques->count() > 1 ? $this->sesBoutiques($boutiques, $debut) : null,
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

    /** @return array<string, mixed> */
    private function courbe(callable $ventes, mixed $jour, CarbonImmutable $debut, CarbonImmutable $fin, string $devise): array
    {
        $parJour = $ventes()->whereBetween($jour, [$debut->toDateString(), $fin->toDateString()])
            ->groupBy(DB::raw('COALESCE(jour_affaire, DATE(created_at))'))
            ->select(DB::raw('COALESCE(jour_affaire, DATE(created_at)) as jour'), DB::raw('SUM(total) as ca'), DB::raw('COUNT(*) as n'))
            ->get()->keyBy(fn ($r) => substr((string) $r->jour, 0, 10));
        $points = [];
        for ($d = $debut; $d->lte($fin); $d = $d->addDay()) {
            $r = $parJour[$d->toDateString()] ?? null;
            $points[] = ['date' => $d->toDateString(), 'valeur' => (int) ($r->ca ?? 0), 'ventes' => (int) ($r->n ?? 0)];
        }

        return ['titre' => 'Chiffre d’affaires des 30 derniers jours', 'devise' => $devise, 'points' => $points];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, mixed>|null
     */
    private function meilleursArticles(array $ids, CarbonImmutable $debut, string $devise): ?array
    {
        $lignes = DB::table('lignes_vente')->join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->whereIn('ventes.boutique_id', $ids)->where('ventes.statut', 'validee')
            ->where(DB::raw('COALESCE(ventes.jour_affaire, DATE(ventes.created_at))'), '>=', $debut->toDateString())
            ->groupBy('lignes_vente.nom_produit')
            ->select('lignes_vente.nom_produit', DB::raw('SUM(lignes_vente.quantite) as quantite'), DB::raw('SUM(lignes_vente.total_ligne) as ca'))
            ->orderByDesc('ca')->limit(10)->get();
        if ($lignes->isEmpty()) {
            return null;
        }

        return [
            'titre' => 'Meilleurs articles (30 jours)',
            'colonnes' => [['libelle' => 'Article', 'type' => 'texte'], ['libelle' => 'Quantité', 'type' => 'nombre'], ['libelle' => 'Chiffre d’affaires', 'type' => 'montant']],
            'devise' => $devise,
            'lignes' => $lignes->map(fn ($l) => [(string) $l->nom_produit, (int) round((float) $l->quantite), (int) $l->ca])->all(),
        ];
    }

    /**
     * @param  Collection<int, Boutique>  $boutiques
     * @return array<string, mixed>
     */
    private function sesBoutiques(Collection $boutiques, CarbonImmutable $debut): array
    {
        $ids = $boutiques->pluck('id')->all();
        $stats = DB::table('ventes')->whereIn('boutique_id', $ids)->where('statut', 'validee')->groupBy('boutique_id')
            ->select('boutique_id',
                DB::raw("SUM(CASE WHEN COALESCE(jour_affaire, DATE(created_at)) >= '{$debut->toDateString()}' THEN total ELSE 0 END) as ca"),
                DB::raw("SUM(CASE WHEN COALESCE(jour_affaire, DATE(created_at)) >= '{$debut->toDateString()}' THEN 1 ELSE 0 END) as n"),
                DB::raw('MAX(created_at) as derniere'))
            ->get()->keyBy('boutique_id');

        return [
            'titre' => 'Ses boutiques',
            'colonnes' => [['libelle' => 'Boutique', 'type' => 'texte'], ['libelle' => 'Chiffre d’affaires (30 jours)', 'type' => 'texte'], ['libelle' => 'Ventes (30 jours)', 'type' => 'nombre'], ['libelle' => 'Dernière vente', 'type' => 'date']],
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
