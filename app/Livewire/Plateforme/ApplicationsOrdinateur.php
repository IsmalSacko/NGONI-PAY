<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Support\InstallateursBureau;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Applications ordinateur : les installateurs Windows et macOS proposés sur
 * la vitrine. Un nouveau fichier se dépose ici, envoyé par morceaux avec sa
 * progression ({@see \App\Http\Controllers\Plateforme\EnvoiInstallateurController}).
 */
#[Layout('layouts.plateforme', ['title' => 'Applications ordinateur'])]
class ApplicationsOrdinateur extends Component
{
    public function render()
    {
        $enLigne = collect(InstallateursBureau::disponibles())->keyBy('systeme');

        return view('livewire.plateforme.applications-ordinateur', [
            'systemes' => collect((array) config('bureau.systemes'))->map(fn (array $c, string $code) => [
                'code' => $code,
                'nom' => $c['nom'],
                'extension' => pathinfo($c['fichier'], PATHINFO_EXTENSION),
                'requis' => $c['requis'],
                'enLigne' => $enLigne->get($code),
                'version' => InstallateursBureau::version($code),
            ])->values(),
        ]);
    }
}
