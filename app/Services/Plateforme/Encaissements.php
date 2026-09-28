<?php

declare(strict_types=1);

namespace App\Services\Plateforme;

use App\Enums\Country;
use App\Models\Vente;
use App\Support\Money\Currencies;
use App\Support\Money\Reechelonnement;
use App\Support\Periode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Encaissements de toutes les boutiques, pour la console de l'exploitant.
 *
 * Les montants sont ramenés au franc CFA quand la parité est fixe (XOF, XAF
 * à 1 pour 1, l'euro et ce qui y est arrimé) ; une boutique dans une autre
 * devise (cedi, naira…) garde ses montants dans sa devise, à part : les
 * additionner au reste sans taux sûr fausserait le total. Le classement par
 * activité, lui, se fait au nombre de ventes, qui ne dépend d'aucune devise.
 */
class Encaissements
{
    /** @var array<string, Collection<int, object>> */
    private array $lignes = [];

    /**
     * Par boutique sur la période : ventes, montant dans sa devise, et en
     * FCFA si convertible. Trié par montant FCFA décroissant.
     *
     * @return Collection<int, object{boutique_id: string, nom: string, pays: string, devise: string, ventes: int, montant: int, fcfa: ?int}>
     */
    public function parBoutique(Periode $periode): Collection
    {
        return $this->lignes($periode)
            ->sortByDesc(fn ($l) => [$l->fcfa ?? -1, $l->ventes])
            ->values();
    }

    public function totalFcfa(Periode $periode): int
    {
        return (int) $this->lignes($periode)->sum(fn ($l) => $l->fcfa ?? 0);
    }

    public function nombreDeVentes(Periode $periode): int
    {
        return (int) $this->lignes($periode)->sum('ventes');
    }

    /**
     * Ce qui n'a pu être converti, par devise : [devise => montant en unités mineures].
     *
     * @return array<string, int>
     */
    public function horsFcfa(Periode $periode): array
    {
        return $this->lignes($periode)
            ->whereNull('fcfa')
            ->groupBy('devise')
            ->map(fn (Collection $l) => (int) $l->sum('montant'))
            ->sortKeys()
            ->all();
    }

    /**
     * Les boutiques les plus actives (nombre de ventes), avec leur variation
     * sur la période précédente de même durée.
     *
     * @return Collection<int, object>
     */
    public function plusActives(Periode $periode, int $combien = 5): Collection
    {
        $avant = $this->lignes($periode->precedente())->pluck('ventes', 'boutique_id');

        return $this->lignes($periode)
            ->sortByDesc('ventes')
            ->take($combien)
            ->map(fn ($l) => (object) [...(array) $l, 'variation' => Periode::variation($l->ventes, (int) ($avant[$l->boutique_id] ?? 0))])
            ->values();
    }

    /**
     * Montant FCFA par pays des boutiques, du plus grand au plus petit.
     *
     * @return Collection<string, int> [code pays => FCFA]
     */
    public function parPays(Periode $periode): Collection
    {
        return $this->lignes($periode)
            ->whereNotNull('fcfa')
            ->groupBy('pays')
            ->map(fn (Collection $l) => (int) $l->sum('fcfa'))
            ->filter()
            ->sortDesc();
    }

    /**
     * Total FCFA jour par jour, jours sans vente compris (la courbe ne saute pas de jour).
     *
     * @return array<string, int> [Y-m-d => FCFA]
     */
    public function parJour(Periode $periode): array
    {
        $jours = array_fill_keys($periode->jours(), 0);

        $lignes = Vente::withoutBoutiqueScope()->valides()
            ->join('boutiques', 'boutiques.id', '=', 'ventes.boutique_id')
            ->whereBetween('ventes.created_at', [$periode->debut, $periode->fin])
            ->groupBy(DB::raw('DATE(ventes.created_at)'), 'boutiques.devise')
            ->get([DB::raw('DATE(ventes.created_at) as jour'), 'boutiques.devise', DB::raw('SUM(ventes.total) as montant')]);

        foreach ($lignes as $ligne) {
            $fcfa = self::enFcfa((int) $ligne->montant, (string) $ligne->devise);
            if ($fcfa !== null && array_key_exists((string) $ligne->jour, $jours)) {
                $jours[(string) $ligne->jour] += $fcfa;
            }
        }

        return $jours;
    }

    /** Montant en unités mineures de `$devise`, en francs CFA ; null sans parité fixe. */
    public static function enFcfa(int $montant, string $devise): ?int
    {
        $taux = Reechelonnement::tauxFixe('XOF', $devise);

        return $taux === null ? null : (int) round($montant / (10 ** Currencies::decimals($devise)) * $taux);
    }

    public static function libellePays(string $code): string
    {
        $pays = Country::tryFrom($code);

        return $pays === null ? $code : $pays->flag().' '.$pays->label();
    }

    /** Une seule requête par période, partagée par tous les blocs du tableau. */
    private function lignes(Periode $periode): Collection
    {
        $cle = $periode->debut->toIso8601String().'|'.$periode->fin->toIso8601String();

        return $this->lignes[$cle] ??= Vente::withoutBoutiqueScope()->valides()
            ->join('boutiques', 'boutiques.id', '=', 'ventes.boutique_id')
            ->whereBetween('ventes.created_at', [$periode->debut, $periode->fin])
            ->groupBy('ventes.boutique_id', 'boutiques.nom', 'boutiques.pays', 'boutiques.devise')
            ->get([
                'ventes.boutique_id', 'boutiques.nom', 'boutiques.pays', 'boutiques.devise',
                DB::raw('COUNT(*) as ventes'), DB::raw('SUM(ventes.total) as montant'),
            ])
            ->map(fn ($l) => (object) [
                'boutique_id' => (string) $l->boutique_id,
                'nom' => (string) $l->nom,
                'pays' => (string) $l->pays,
                'devise' => (string) $l->devise,
                'ventes' => (int) $l->ventes,
                'montant' => (int) $l->montant,
                'fcfa' => self::enFcfa((int) $l->montant, (string) $l->devise),
            ]);
    }
}
