<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Services\Console\AbonnementsConsole;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Abonnements payés, par canal : Jèko, pawaPay, FedaPay (en ligne, activés
 * seuls) ou validés à la main ; totaux encaissés sur la période choisie.
 */
#[Layout('layouts.plateforme', ['title' => 'Abonnements'])]
class Abonnements extends Component
{
    use WithPagination;

    /** Voir AbonnementsConsole::CANAUX. */
    public string $canal = '';

    /** Voir AbonnementsConsole::PERIODES. */
    public string $periode = 'mois';

    public string $recherche = '';

    public function updating(string $propriete): void
    {
        if (in_array($propriete, ['canal', 'periode', 'recherche'], true)) {
            $this->resetPage();
        }
    }

    public function render(AbonnementsConsole $console)
    {
        return view('livewire.plateforme.abonnements', [
            'paiements' => $console->requete(['canal' => $this->canal, 'periode' => $this->periode, 'recherche' => $this->recherche])->paginate(25),
            'totaux' => $console->totaux($this->periode),
            'accordes' => $console->accordes(),
        ]);
    }
}
