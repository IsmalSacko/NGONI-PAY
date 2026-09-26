<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\LigneVente;
use App\Models\Produit;
use App\Models\Vente;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    use EstScopeParBoutique;

    public function render()
    {
        $boutique = \App\Models\Boutique::find($this->boutiqueActiveId());
        $ventesJour = Vente::whereDate('created_at', today())->get();

        $total = (int) $ventesJour->sum('total');
        $tickets = $ventesJour->count();
        $tva = (int) $ventesJour->sum('tva');
        $panierMoyen = $tickets > 0 ? (int) round($total / $tickets) : 0;

        $parHeure = $ventesJour->groupBy(fn (Vente $v) => $v->created_at->format('H'))
            ->map(fn ($groupe) => (int) $groupe->sum('total'));
        $ventesParHeure = collect(range(6, 22))->map(fn (int $h) => [
            'heure' => $h,
            'total' => $parHeure->get(str_pad((string) $h, 2, '0', STR_PAD_LEFT), 0),
        ]);
        $maxHeure = max(1, $ventesParHeure->max('total'));

        $parMoyen = $ventesJour->groupBy('moyen_paiement')
            ->map(fn ($groupe, $moyen) => [
                'label' => $groupe->first()->moyen_paiement->label(),
                'total' => (int) $groupe->sum('total'),
                'pct' => $total > 0 ? round($groupe->sum('total') / $total * 100) : 0,
            ])->sortByDesc('total')->values();

        $topProduits = LigneVente::query()
            ->whereHas('vente', fn ($q) => $q->whereDate('created_at', today()))
            ->selectRaw('nom_produit, sum(quantite) as quantite, sum(total_ligne) as total')
            ->groupBy('nom_produit')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        $alertes = Produit::where('actif', true)
            ->where(fn ($q) => $q->where('stock', '<=', 0)->orWhereColumn('stock', '<=', 'seuil_alerte'))
            ->orderBy('stock')
            ->limit(6)
            ->get();

        return view('livewire.dashboard', [
            'boutique' => $boutique,
            'total' => $total,
            'tickets' => $tickets,
            'tva' => $tva,
            'panierMoyen' => $panierMoyen,
            'ventesParHeure' => $ventesParHeure,
            'maxHeure' => $maxHeure,
            'parMoyen' => $parMoyen,
            'topProduits' => $topProduits,
            'alertes' => $alertes,
        ]);
    }
}
