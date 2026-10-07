<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MoyenPaiement;
use App\Models\Client;
use App\Models\Depense;
use App\Models\EncaissementPressing;
use App\Models\LigneVente;
use App\Models\ReglementCredit;
use App\Models\Vente;
use App\Support\Quantite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rapport d'activité de la boutique active sur une période (bornes incluses) :
 * clôture du jour (ticket Z), semaine, mois. Seules les ventes validées
 * comptent ; les annulées sont chiffrées à part. La marge utilise le prix
 * d'achat mémorisé au moment de la vente, avant remise.
 */
class Rapports
{
    /** @return array<string, mixed> */
    public function periode(Carbon $du, Carbon $au): array
    {
        $debut = $du->copy()->startOfDay();
        $fin = $au->copy()->endOfDay();
        // Par journée d'affaires : une vente faite après une clôture compte
        // pour la journée suivante, comme sur le ticket Z.
        $dans = fn ($q) => $q->whereBetween('ventes.jour_affaire', [$debut->toDateString(), $fin->toDateString()]);
        $valides = fn () => $dans(Vente::valides());

        $total = (int) $valides()->sum('total');
        $nombre = $valides()->count();

        $annulees = $dans(Vente::where('statut', Vente::STATUT_ANNULEE));

        // Coût : prix d'achat mémorisé à la vente, sinon prix d'achat actuel de
        // l'article (estimation). Les montants libres n'ont pas de coût.
        $marge = LigneVente::query()
            ->from('lignes_vente as lv')
            ->leftJoin('produits as p', 'p.id', '=', 'lv.produit_id')
            ->whereIn('lv.vente_id', $valides()->select('ventes.id'))
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(lv.prix_achat, p.prix_achat) IS NOT NULL THEN lv.total_ligne END), 0) as chiffre')
            ->selectRaw('COALESCE(SUM(COALESCE(lv.prix_achat, p.prix_achat) * lv.quantite * lv.contenance), 0) as cout')
            ->selectRaw('COALESCE(SUM(CASE WHEN lv.prix_achat IS NULL AND p.prix_achat IS NOT NULL THEN lv.total_ligne END), 0) as estime')
            ->selectRaw('COALESCE(SUM(lv.total_ligne), 0) as brut')
            ->first();
        $chiffreCouvert = (int) $marge->chiffre;
        $cout = (int) $marge->cout;
        $brut = (int) $marge->brut;

        return [
            'du' => $debut->toDateString(),
            'au' => $fin->toDateString(),
            'ventes' => [
                'nombre' => $nombre,
                'total' => $total,
                'remises' => (int) $valides()->sum('remise'),
                'tva' => (int) $valides()->sum('tva'),
                'panier_moyen' => $nombre > 0 ? (int) round($total / $nombre) : 0,
                'articles' => Quantite::normaliser(LigneVente::whereHas('vente', fn ($q) => $dans($q->valides()))->sum('quantite')),
            ],
            'annulees' => ['nombre' => (clone $annulees)->count(), 'total' => (int) (clone $annulees)->sum('total')],
            'par_jour' => $valides()
                ->selectRaw('jour_affaire as jour, COUNT(*) as nombre, SUM(total) as total')
                ->groupBy('jour_affaire')->orderBy('jour_affaire')->get()
                ->map(fn ($r) => ['date' => (string) $r->jour, 'nombre' => (int) $r->nombre, 'total' => (int) $r->total]),
            // Par moyen, ce qui a été payé ; le reste dû des ventes forme la
            // ligne « Crédit client ». Le tout fait le chiffre des ventes.
            'par_moyen' => $this->parMoyen($valides),
            // Vente en gros : ce qui s'est vendu au prix de gros, ligne par ligne.
            'par_tarif' => [
                'gros' => (int) LigneVente::whereIn('vente_id', $valides()->select('ventes.id'))->where('prix_gros', true)->sum('total_ligne'),
                'detail' => (int) LigneVente::whereIn('vente_id', $valides()->select('ventes.id'))->where('prix_gros', false)->sum('total_ligne'),
            ],
            'par_caissier' => $valides()
                ->join('users', 'users.id', '=', 'ventes.user_id')
                ->selectRaw('users.name as nom, COUNT(*) as nombre, SUM(ventes.total) as total')
                ->groupBy('users.name')->orderByDesc('total')->get()
                ->map(fn ($r) => ['nom' => (string) $r->nom, 'nombre' => (int) $r->nombre, 'total' => (int) $r->total]),
            // Par article, sous son nom actuel : un article renommé en cours de
            // période ne se coupe pas en deux. Un montant libre (sans article)
            // se regroupe par son libellé.
            'top_produits' => LigneVente::query()->from('lignes_vente as lv')
                ->leftJoin('produits as p', 'p.id', '=', 'lv.produit_id')
                ->whereIn('lv.vente_id', $valides()->select('ventes.id'))
                ->selectRaw('COALESCE(MAX(p.nom), MAX(lv.nom_produit)) as nom, MAX(p.unite) as unite, SUM(lv.quantite * lv.contenance) as quantite, SUM(lv.total_ligne) as total')
                ->groupBy(DB::raw('COALESCE(lv.produit_id, lv.nom_produit)'))->orderByDesc('total')->limit(10)->get()
                ->map(fn ($r) => ['nom' => (string) $r->nom, 'quantite' => Quantite::normaliser($r->quantite), 'unite' => $r->unite, 'total' => (int) $r->total]),
            'marge' => [
                'chiffre_couvert' => $chiffreCouvert,
                'cout' => $cout,
                'marge' => $chiffreCouvert - $cout,
                'taux' => $chiffreCouvert > 0 ? round(($chiffreCouvert - $cout) * 100 / $chiffreCouvert, 1) : null,
                // Part des ventes (avant remise) dont le coût est connu, et part estimée.
                'couverture' => $brut > 0 ? (int) round($chiffreCouvert * 100 / $brut) : null,
                'estimee' => (int) $marge->estime > 0,
            ],
            'credit' => [
                // Vendu à crédit : la part des ventes de la période non payée sur
                // le moment. Fixe : il explique ventes = payé + vendu à crédit.
                'accorde' => $accorde = (int) $valides()->sum('reste_du'),
                // Reste à encaisser : ce que ces clients doivent encore
                // aujourd'hui — leur crédit de la période, plafonné à leur dette
                // actuelle. Baisse à chaque remboursement.
                'encore_du' => $encoreDu = $this->encoreDu($valides),
                'rembourse' => $rembourse = (int) ReglementCredit::whereBetween('created_at', [$debut, $fin])->sum('montant'),
            ],
            // L'argent réellement reçu : le payé des ventes et les dettes
            // remboursées. Le chiffre des ventes, lui, compte aussi le crédit.
            // Pressing : l'acompte compte le jour du dépôt, pas au retrait.
            'encaisse' => [
                'ventes' => $payeVentes = (int) $valides()->sum(DB::raw('montant_paye - acompte_deduit')),
                'remboursements' => $rembourse,
                'acomptes' => $acomptes = (int) EncaissementPressing::whereBetween('created_at', [$debut, $fin])->get()->sum(fn (EncaissementPressing $e) => $e->signe()),
                'total' => $payeVentes + $rembourse + $acomptes,
                // Pressing : dépenses de la période (sorties, hors chiffre des ventes).
                'depenses' => (int) Depense::whereDate('jour', '>=', $debut)->whereDate('jour', '<=', $fin)->sum('montant'),
                'a_recevoir' => $encoreDu,
            ],
        ];
    }

    /**
     * @param  \Closure(): Builder<Vente>  $valides
     * @return Collection<int, array{moyen: string, libelle: string, nombre: int, total: int}>
     */
    private function parMoyen(\Closure $valides)
    {
        $libelle = fn (string $m) => MoyenPaiement::tryFrom($m)?->label() ?? $m;
        $payes = $valides()->where('montant_paye', '>', 0)
            ->selectRaw('moyen_paiement, COUNT(*) as nombre, SUM(montant_paye) as total')
            ->groupBy('moyen_paiement')->get()
            ->map(function ($r) use ($libelle) {
                $moyen = $r->moyen_paiement instanceof MoyenPaiement ? $r->moyen_paiement->value : (string) $r->moyen_paiement;

                return ['moyen' => $moyen, 'libelle' => $libelle($moyen), 'nombre' => (int) $r->nombre, 'total' => (int) $r->total];
            });
        $credit = $valides()->where('reste_du', '>', 0);
        $nombreCredit = (clone $credit)->count();
        if ($nombreCredit > 0) {
            $m = MoyenPaiement::CreditClient->value;
            // La part non payée sur le moment (fixe) ; ce qu'il en reste à
            // encaisser se lit à part (credit.encore_du).
            $payes->push(['moyen' => $m, 'libelle' => 'Vendu à crédit', 'nombre' => $nombreCredit, 'total' => (int) (clone $credit)->sum('reste_du')]);
        }

        return $payes->sortByDesc('total')->values();
    }

    /**
     * Ce que les clients doivent encore sur les ventes à crédit de la période :
     * pour chacun, son crédit de la période plafonné à sa dette d'aujourd'hui
     * (un remboursement règle d'abord sa dette, quelle que soit la vente).
     *
     * @param  \Closure(): Builder<Vente>  $valides
     */
    private function encoreDu(\Closure $valides): int
    {
        $parClient = $valides()->where('reste_du', '>', 0)->whereNotNull('client_id')
            ->selectRaw('client_id, SUM(reste_du) as reste')->groupBy('client_id')->pluck('reste', 'client_id');
        if ($parClient->isEmpty()) {
            return 0;
        }
        $soldes = Client::query()->whereKey($parClient->keys())->avecSoldeDu()->get()
            ->mapWithKeys(fn (Client $c) => [$c->id => max(0, (int) $c->credit_total - (int) $c->reglements_total)]);

        return (int) $parClient->map(fn ($reste, $id) => min((int) $reste, (int) ($soldes[$id] ?? 0)))->sum();
    }
}
