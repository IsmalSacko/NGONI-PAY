<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Services\Plateforme\Encaissements;
use App\Services\Plateforme\Presences;
use App\Support\CouleursGraphiques;
use App\Support\Periode;
use App\Support\Presence\Appareil;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Vue d'ensemble de l'exploitant : qui utilise l'application, quelles
 * boutiques encaissent le plus, d'où et sur quels appareils.
 */
#[Layout('layouts.plateforme', ['title' => 'Tableau de bord'])]
class Tableau extends Component
{
    #[Url(as: 'periode')]
    public string $periode = '7j';

    public function render(Encaissements $encaissements, Presences $presences)
    {
        $periode = Periode::depuis($this->periode);
        $this->periode = $periode->code;
        $precedente = $periode->precedente();

        $total = $encaissements->totalFcfa($periode);
        $ventes = $encaissements->nombreDeVentes($periode);
        $boutiques = Boutique::count();
        $nouvelles = Boutique::where('created_at', '>=', $periode->debut)->count();
        $nouvellesAvant = Boutique::whereBetween('created_at', [$precedente->debut, $precedente->fin])->count();
        $actifsAbonnement = Abonnement::avecCompte()->where('est_actif', true)
            ->where(fn ($q) => $q->whereNull('fin')->orWhereDate('fin', '>=', today()))
            ->count();

        return view('livewire.plateforme.tableau', [
            'choix' => Periode::CHOIX,
            'periodeLibelle' => $periode->libelle(),
            'indicateurs' => [
                'boutiques' => ['valeur' => $boutiques, 'detail' => "+{$nouvelles} sur la période", 'variation' => Periode::variation($nouvelles, $nouvellesAvant)],
                'utilisateurs' => ['valeur' => $presences->actifs($periode), 'detail' => 'sur '.$presences->total().' comptes'],
                'encaissements' => ['valeur' => $total, 'variation' => Periode::variation($total, $encaissements->totalFcfa($precedente))],
                'ventes' => ['valeur' => $ventes, 'variation' => Periode::variation($ventes, $encaissements->nombreDeVentes($precedente))],
            ],
            'parBoutique' => $encaissements->parBoutique($periode)->take(8),
            'total' => $total,
            'horsFcfa' => $encaissements->horsFcfa($periode),
            'parJour' => $encaissements->parJour($periode),
            'plusActives' => $encaissements->plusActives($periode),
            'parPays' => $this->partsParPays($encaissements->parPays($periode), fn (int $v) => number_format($v, 0, ',', ' ').' F'),
            'utilisateursParPays' => $presences->parPays($periode),
            'parAppareil' => $presences->parAppareil($periode)
                ->map(fn (int $n, string $p) => [
                    'libelle' => Appareil::PLATEFORMES[$p] ?? 'Inconnu',
                    'valeur' => $n,
                    'couleur' => CouleursGraphiques::plateforme($p),
                ])->values()->all(),
            'abonnements' => [
                'actifs' => $actifsAbonnement,
                'expires' => Abonnement::avecCompte()->count() - $actifsAbonnement,
                'demandes' => DemandeAbonnement::enAttente()->count(),
            ],
        ]);
    }

    /**
     * Les pays qui ont leur couleur, puis « Autres » regroupé (jamais une
     * couleur inventée pour le septième pays).
     *
     * @param  Collection<string, int>  $montants
     * @return list<array{libelle: string, valeur: int, couleur: string, detail: string}>
     */
    private function partsParPays(Collection $montants, callable $format): array
    {
        [$nommes, $autres] = $montants->partition(fn (int $v, string $pays) => CouleursGraphiques::aSaCouleur($pays));

        $parts = $nommes->map(fn (int $v, string $pays) => [
            'libelle' => Encaissements::libellePays($pays),
            'valeur' => $v,
            'couleur' => CouleursGraphiques::pays($pays),
            'detail' => $format($v),
        ])->values()->all();

        if ($autres->sum() > 0) {
            $parts[] = ['libelle' => 'Autres ('.$autres->count().')', 'valeur' => (int) $autres->sum(), 'couleur' => CouleursGraphiques::NEUTRE, 'detail' => $format((int) $autres->sum())];
        }

        return $parts;
    }
}
