<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\MoyenPaiement;
use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Boutique;
use App\Models\LigneVente;
use App\Models\Produit;
use App\Models\Vente;
use App\Models\Plan;
use App\Services\AbonnementService;
use App\Services\Elan;
use App\Services\Journee;
use App\Services\Rapports;
use App\Support\Fuseau;
use App\Support\Money\Montant;
use Illuminate\Support\Facades\Auth;
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

    /** Fenêtre « Objectif du mois » ouverte. */
    public bool $objectifOuvert = false;

    public string $objectif = '';

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

    /** L'objectif du mois, comme au Pilotage : réglé par l'admin, offre qui le comprend. */
    private function autoriserObjectif(): Boutique
    {
        Auth::user()->can('boutique.update') || abort(403);
        $boutique = Boutique::findOrFail($this->boutiqueActiveId());
        app(AbonnementService::class)->permet($boutique, Plan::OBJECTIF_MOIS) || abort(403);

        return $boutique;
    }

    public function ouvrirObjectif(): void
    {
        $boutique = $this->autoriserObjectif();
        $this->resetValidation();
        $this->objectif = $boutique->objectif_mensuel === null ? '' : Montant::saisie((int) $boutique->objectif_mensuel);
        $this->objectifOuvert = true;
    }

    public function enregistrerObjectif(): void
    {
        $boutique = $this->autoriserObjectif();
        // Vide : plus d'objectif.
        $montant = trim($this->objectif) === '' ? null : Montant::parse($this->objectif);
        if (trim($this->objectif) !== '' && ($montant === null || $montant < 1)) {
            $this->addError('objectif', 'Indiquez un montant, ou laissez vide pour retirer l’objectif.');

            return;
        }
        $boutique->forceFill(['objectif_mensuel' => $montant])->save();
        $this->objectifOuvert = false;
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

        // Restaurant, paiement mixte : chaque part va à son moyen.
        $parts = [];
        foreach ($ventesJour as $v) {
            foreach ($v->partsParMoyen((int) $v->total) as $moyen => $montant) {
                $parts[$moyen] = ($parts[$moyen] ?? 0) + $montant;
            }
        }
        $parMoyen = collect($parts)
            ->map(fn (int $somme, string $moyen) => [
                'label' => MoyenPaiement::tryFrom($moyen)?->label() ?? $moyen,
                'total' => $somme,
                'pct' => $total > 0 ? round($somme / $total * 100) : 0,
            ])->sortByDesc('total')->values();

        $topProduits = LigneVente::query()
            ->whereHas('vente', fn ($q) => $q->valides()->whereDate('created_at', today()))
            ->selectRaw('nom_produit, sum(quantite * contenance) as quantite, sum(total_ligne) as total')
            ->groupBy('nom_produit')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        // Un pressing ne suit pas de stock : pas d'alerte.
        $alertes = $boutique?->suitLeStock() === false ? collect() : Produit::pourActivite()->where('actif', true)
            ->where(fn ($q) => $q->where('stock', '<=', 0)->orWhereColumn('stock', '<=', 'seuil_alerte'))
            ->orderBy('stock')
            ->limit(6)
            ->get();

        $objectifInclus = $boutique !== null && app(AbonnementService::class)->permet($boutique, Plan::OBJECTIF_MOIS);

        return view('livewire.dashboard', [
            'boutique' => $boutique,
            // Ce qui donne envie d'ouvrir la caisse : l'objectif du mois et la série de jours de vente.
            'elan' => $objectifInclus ? app(Elan::class)->pour($boutique) : null,
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
