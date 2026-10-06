<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Boutique;
use App\Models\LigneVente;
use App\Models\Produit;
use App\Models\Vente;
use App\Services\Journee;
use App\Services\Rapports;
use App\Support\Fuseau;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    use EstScopeParBoutique;

    /** Pages d'accueil possibles, dans l'ordre du menu, avec leur permission. */
    private const REPLIS = [
        'produits.index' => 'produits.view', 'stocks.index' => 'stocks.view', 'achats.index' => 'achats.view',
        'ventes.index' => 'ventes.view', 'clients.index' => 'clients.view', 'utilisateurs.index' => 'utilisateurs.view',
    ];

    public function mount()
    {
        // Le chiffre d'affaires est un droit du membre (Permissions::DROITS) :
        // sans lui, l'accueil est la première page qu'il peut ouvrir.
        if (auth()->user()->can('dashboard.view')) {
            return null;
        }
        foreach (self::REPLIS as $route => $permission) {
            if (auth()->user()->can($permission)) {
                return $this->redirectRoute($route, navigate: false);
            }
        }
        abort(403);
    }

    public function render()
    {
        $boutique = Boutique::find($this->boutiqueActiveId());
        // Journée d'affaires en cours, comme le rapport et le ticket Z.
        $ventesJour = Vente::valides()->where('jour_affaire', app(Journee::class)->courante()->toDateString())->get();

        $total = (int) $ventesJour->sum('total');
        $tickets = $ventesJour->count();
        $tva = (int) $ventesJour->sum('tva');
        $panierMoyen = $tickets > 0 ? (int) round($total / $tickets) : 0;

        $parHeure = $ventesJour->groupBy(fn (Vente $v) => Fuseau::heure($v->created_at, 'H', $boutique?->pays))
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
            ->whereHas('vente', fn ($q) => $q->valides()->whereDate('created_at', today()))
            ->selectRaw('nom_produit, sum(quantite * contenance) as quantite, sum(total_ligne) as total')
            ->groupBy('nom_produit')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        // Un pressing ne suit pas de stock : pas d'alerte.
        $alertes = $boutique?->suitLeStock() === false ? collect() : Produit::where('actif', true)
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
            // Comme la carte du Pilotage : l'encaissé et ce qui reste dû aujourd'hui.
            'jour' => ($jour = app(Journee::class)->courante()) ? app(Rapports::class)->periode($jour, $jour) : null,
        ]);
    }
}
