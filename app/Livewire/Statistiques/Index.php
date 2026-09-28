<?php

declare(strict_types=1);

namespace App\Livewire\Statistiques;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Boutique;
use App\Models\Plan;
use App\Services\AbonnementService;
use App\Services\Rapports;
use App\Services\Statistiques;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Statistiques de la boutique : ce qui aide le commerçant à décider (quand
 * renforcer la caisse, quoi racheter, quoi écouler, qui relancer). Les
 * rapports comptables et le ticket Z restent sur la page Rapports.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique;

    public const PERIODES = [
        '7j' => '7 jours',
        '30j' => '30 jours',
        'mois' => 'Ce mois',
        'mois_dernier' => 'Mois dernier',
        '90j' => '3 mois',
    ];

    #[Url(as: 'periode')]
    public string $periode = '30j';

    /** @return array{Carbon, Carbon} */
    private function bornes(): array
    {
        return match ($this->periode) {
            '7j' => [today()->subDays(6), today()],
            'mois' => [today()->startOfMonth(), today()],
            'mois_dernier' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            '90j' => [today()->subDays(89), today()],
            default => [today()->subDays(29), today()],
        };
    }

    public function render(Rapports $rapports, Statistiques $statistiques, AbonnementService $abonnements)
    {
        if (! array_key_exists($this->periode, self::PERIODES)) {
            $this->periode = '30j';
        }
        [$du, $au] = $this->bornes();
        $boutique = Boutique::findOrFail($this->boutiqueActiveId());
        $disponible = $abonnements->permet($boutique, Plan::STATISTIQUES_AVANCEES);

        return view('livewire.statistiques.index', [
            'periodes' => self::PERIODES,
            'boutique' => $boutique,
            'du' => $du,
            'au' => $au,
            'rapport' => $rapports->periode($du, $au),
            'comparaison' => $statistiques->comparaison($du, $au),
            'disponible' => $disponible,
            'analyse' => $disponible ? $statistiques->analyse($boutique, $du, $au) : null,
        ])->title('Statistiques');
    }
}
