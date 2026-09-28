<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Services\Plateforme\Presences;
use Livewire\Component;

/**
 * Les derniers utilisateurs vus. Composant à part : il se rafraîchit seul
 * chaque minute (le rythme auquel l'application se signale), sans recalculer
 * les encaissements du reste de la page.
 */
class EnLigne extends Component
{
    public int $combien = 8;

    public function render(Presences $presences)
    {
        return view('livewire.plateforme.en-ligne', [
            'enLigne' => $presences->nombreEnLigne(),
            'recents' => $presences->recents($this->combien),
        ]);
    }
}
