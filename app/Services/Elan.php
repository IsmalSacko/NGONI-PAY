<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\Vente;
use Illuminate\Support\Carbon;

/**
 * Ce qui donne envie d'ouvrir la caisse chaque jour : l'objectif du mois et
 * la série de journées avec au moins une vente.
 *
 * La série reste vivante tant que la journée en cours n'est pas finie : sans
 * vente encore aujourd'hui, elle compte jusqu'à hier — un commerçant qui ouvre
 * l'app le matin ne doit pas voir « 0 jour » après une semaine sans faute.
 * Toujours dans la boutique active (contexte tenant), par journée d'affaires.
 */
class Elan
{
    /** Au-delà, la série n'est plus recherchée : un an d'affilée suffit à la lire. */
    private const JOURS_MAX = 400;

    public function __construct(private readonly Journee $journee) {}

    /**
     * @return array{objectif: array{montant: ?int, realise: int, pourcentage: ?int}, serie: array{jours: int, aujourdhui: bool}}
     */
    public function pour(Boutique $boutique): array
    {
        $jour = $this->journee->courante();

        $realise = (int) Vente::valides()
            ->whereBetween('jour_affaire', [$jour->copy()->startOfMonth()->toDateString(), $jour->copy()->endOfMonth()->toDateString()])
            ->sum('total');
        $objectif = $boutique->objectif_mensuel === null ? null : (int) $boutique->objectif_mensuel;

        return [
            'objectif' => [
                'montant' => $objectif,
                'realise' => $realise,
                'pourcentage' => $objectif ? (int) floor($realise * 100 / $objectif) : null,
            ],
            'serie' => $this->serie($jour),
        ];
    }

    /** @return array{jours: int, aujourdhui: bool} */
    private function serie(Carbon $jour): array
    {
        $jours = Vente::valides()
            ->where('jour_affaire', '>', $jour->copy()->subDays(self::JOURS_MAX)->toDateString())
            ->where('jour_affaire', '<=', $jour->toDateString())
            ->distinct()->orderByDesc('jour_affaire')
            ->pluck('jour_affaire')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip();

        $aujourdhui = $jours->has($jour->toDateString());
        $curseur = $aujourdhui ? $jour->copy() : $jour->copy()->subDay();
        $serie = 0;
        while ($jours->has($curseur->toDateString())) {
            $serie++;
            $curseur->subDay();
        }

        return ['jours' => $serie, 'aujourdhui' => $aujourdhui];
    }
}
