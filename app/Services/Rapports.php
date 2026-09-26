<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MoyenPaiement;
use App\Models\LigneVente;
use App\Models\ReglementCredit;
use App\Models\Vente;
use Illuminate\Support\Carbon;
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
        $valides = fn () => Vente::valides()->whereBetween('ventes.created_at', [$debut, $fin]);

        $total = (int) $valides()->sum('total');
        $nombre = $valides()->count();

        $annulees = Vente::where('statut', Vente::STATUT_ANNULEE)->whereBetween('created_at', [$debut, $fin]);

        // Coût : prix d'achat mémorisé à la vente, sinon prix d'achat actuel de
        // l'article (estimation). Les montants libres n'ont pas de coût.
        $marge = LigneVente::query()
            ->from('lignes_vente as lv')
            ->leftJoin('produits as p', 'p.id', '=', 'lv.produit_id')
            ->whereIn('lv.vente_id', $valides()->select('ventes.id'))
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(lv.prix_achat, p.prix_achat) IS NOT NULL THEN lv.total_ligne END), 0) as chiffre')
            ->selectRaw('COALESCE(SUM(COALESCE(lv.prix_achat, p.prix_achat) * lv.quantite), 0) as cout')
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
                'articles' => (int) LigneVente::whereHas('vente', fn ($q) => $q->valides()->whereBetween('created_at', [$debut, $fin]))->sum('quantite'),
            ],
            'annulees' => ['nombre' => (clone $annulees)->count(), 'total' => (int) (clone $annulees)->sum('total')],
            'par_jour' => $valides()
                ->selectRaw('DATE(created_at) as jour, COUNT(*) as nombre, SUM(total) as total')
                ->groupByRaw('DATE(created_at)')->orderBy('jour')->get()
                ->map(fn ($r) => ['date' => (string) $r->jour, 'nombre' => (int) $r->nombre, 'total' => (int) $r->total]),
            'par_moyen' => $valides()
                ->selectRaw('moyen_paiement, COUNT(*) as nombre, SUM(total) as total')
                ->groupBy('moyen_paiement')->orderByDesc('total')->get()
                ->map(fn ($r) => [
                    'moyen' => $r->moyen_paiement instanceof MoyenPaiement ? $r->moyen_paiement->value : (string) $r->moyen_paiement,
                    'libelle' => ($r->moyen_paiement instanceof MoyenPaiement ? $r->moyen_paiement : MoyenPaiement::tryFrom((string) $r->moyen_paiement))?->label() ?? (string) $r->moyen_paiement,
                    'nombre' => (int) $r->nombre,
                    'total' => (int) $r->total,
                ]),
            'par_caissier' => $valides()
                ->join('users', 'users.id', '=', 'ventes.user_id')
                ->selectRaw('users.name as nom, COUNT(*) as nombre, SUM(ventes.total) as total')
                ->groupBy('users.name')->orderByDesc('total')->get()
                ->map(fn ($r) => ['nom' => (string) $r->nom, 'nombre' => (int) $r->nombre, 'total' => (int) $r->total]),
            'top_produits' => LigneVente::query()
                ->whereHas('vente', fn ($q) => $q->valides()->whereBetween('created_at', [$debut, $fin]))
                ->selectRaw('nom_produit as nom, SUM(quantite) as quantite, SUM(total_ligne) as total')
                ->groupBy('nom_produit')->orderByDesc('total')->limit(10)->get()
                ->map(fn ($r) => ['nom' => (string) $r->nom, 'quantite' => (int) $r->quantite, 'total' => (int) $r->total]),
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
                'accorde' => (int) $valides()->where('moyen_paiement', MoyenPaiement::CreditClient)->sum('total'),
                'rembourse' => (int) ReglementCredit::whereBetween('created_at', [$debut, $fin])->sum('montant'),
            ],
        ];
    }
}
