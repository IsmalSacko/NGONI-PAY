<?php

declare(strict_types=1);

namespace App\Livewire\Rapports;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Boutique;
use App\Services\Rapports;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Rapports de la boutique : clôture du jour, semaine, mois ou période libre,
 * avec exports pour le comptable (Excel, impression PDF).
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique;

    #[Url]
    public string $du = '';

    #[Url]
    public string $au = '';

    public function mount(): void
    {
        $this->du = $this->du ?: today()->toDateString();
        $this->au = $this->au ?: today()->toDateString();
    }

    public function periode(string $choix): void
    {
        [$du, $au] = match ($choix) {
            'hier' => [today()->subDay(), today()->subDay()],
            '7j' => [today()->subDays(6), today()],
            'mois' => [today()->startOfMonth(), today()],
            'mois_dernier' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()],
            default => [today(), today()],
        };
        $this->du = $du->toDateString();
        $this->au = $au->toDateString();
    }

    public function render(Rapports $rapports)
    {
        $du = rescue(fn () => Carbon::parse($this->du), today(), false);
        $au = rescue(fn () => Carbon::parse($this->au), today(), false);
        if ($au->lt($du)) {
            [$du, $au] = [$au, $du];
        }
        if ($du->diffInDays($au) > 366) {
            $du = $au->copy()->subDays(366);
        }

        return view('livewire.rapports.index', [
            'r' => $rapports->periode($du, $au),
            'boutique' => Boutique::find($this->boutiqueActiveId()),
        ]);
    }
}
