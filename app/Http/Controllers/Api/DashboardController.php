<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\Lot;
use App\Models\Produit;
use App\Models\Vente;
use App\Services\Elan;
use App\Services\Journee;
use App\Services\Rapports;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        // Journée d'affaires en cours, comme le rapport et le ticket Z : après une
        // clôture, les ventes du soir comptent déjà pour le lendemain.
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
        $suitLeStock = $boutique->suitLeStock();
        $aujourdhui = Vente::valides()->where('jour_affaire', app(Journee::class)->courante()->toDateString());

        return response()->json([
            'ventes_jour' => [
                'nombre' => (clone $aujourdhui)->count(),
                'total' => (int) (clone $aujourdhui)->sum('total'),
                // Les ventes comptent aussi le crédit : l'encaissé (payé des
                // ventes + remboursements) et le crédit se lisent à part, avec le
                // même calcul que le rapport du jour.
                ...$this->encaissementsDuJour(),
            ],
            'moyens_paiement_jour' => (clone $aujourdhui)
                ->select('moyen_paiement', DB::raw('count(*) as nombre'), DB::raw('sum(total) as total'))
                ->groupBy('moyen_paiement')
                ->get(),
            // Un pressing ne compte pas de stock : ni rupture ni stock faible.
            'produits_en_rupture' => $suitLeStock ? Produit::pourActivite()->where('actif', true)->where('stock', '<=', 0)->count() : 0,
            'produits_stock_faible' => $suitLeStock ? Produit::pourActivite()->where('actif', true)
                ->where('stock', '>', 0)
                ->whereColumn('stock', '<=', 'seuil_alerte')
                ->count() : 0,
            // Objectif du mois et série de journées avec vente.
            ...app(Elan::class)->pour($boutique),
            // Pharmacie : lots périmés ou qui périment sous 90 jours, et ce qu'ils ont coûté.
            ...($boutique->estPharmacie() ? ['a_perimer' => $this->aPerimer()] : []),
        ]);
    }

    /** @return array{lots: int, perimes: int, valeur: int} */
    private function aPerimer(): array
    {
        $lots = Lot::with('produit:id,prix_achat,prix_vente')
            ->where('quantite', '>', 0)->whereNotNull('peremption')
            ->where('peremption', '<=', now()->addDays(90)->toDateString())
            ->whereHas('produit', fn ($q) => $q->where('actif', true))
            ->get();

        return [
            'lots' => $lots->count(),
            'perimes' => $lots->filter(fn (Lot $l) => $l->peremption->isBefore(today()))->count(),
            'valeur' => (int) round($lots->sum(fn (Lot $l) => $l->quantite * ($l->produit->prix_achat ?? $l->produit->prix_vente))),
        ];
    }

    /** @return array{encaisse: int, a_recevoir: int} */
    private function encaissementsDuJour(): array
    {
        $jour = app(Journee::class)->courante();
        $rapport = app(Rapports::class)->periode($jour, $jour);

        return ['encaisse' => $rapport['encaisse']['total'], 'a_recevoir' => $rapport['credit']['encore_du']];
    }
}
