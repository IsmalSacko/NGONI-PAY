<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Fenêtre de temps d'un tableau de bord, et la fenêtre de même durée qui la
 * précède — pour dire « +27 % par rapport à la période précédente ».
 */
final readonly class Periode
{
    public const CHOIX = ['jour' => 'Aujourd’hui', '7j' => '7 jours', '30j' => '30 jours', 'mois' => 'Ce mois'];

    private function __construct(
        public string $code,
        public CarbonImmutable $debut,
        public CarbonImmutable $fin,
    ) {}

    /** Un code inconnu retombe sur 7 jours : l'URL est saisissable à la main. */
    public static function depuis(?string $code, ?CarbonImmutable $maintenant = null): self
    {
        $maintenant ??= CarbonImmutable::now();
        $code = array_key_exists((string) $code, self::CHOIX) ? (string) $code : '7j';

        $debut = match ($code) {
            'jour' => $maintenant->startOfDay(),
            '7j' => $maintenant->subDays(6)->startOfDay(),
            '30j' => $maintenant->subDays(29)->startOfDay(),
            'mois' => $maintenant->startOfMonth(),
        };

        return new self($code, $debut, $maintenant);
    }

    public function precedente(): self
    {
        $duree = $this->debut->diffInSeconds($this->fin);

        return new self($this->code, $this->debut->subSeconds((int) ceil($duree) + 1), $this->debut->subSecond());
    }

    public function libelle(): string
    {
        return self::CHOIX[$this->code];
    }

    /** Les jours couverts, du premier au dernier (pour les courbes). @return list<string> Y-m-d */
    public function jours(): array
    {
        $jours = [];
        for ($jour = $this->debut->startOfDay(); $jour->lte($this->fin); $jour = $jour->addDay()) {
            $jours[] = $jour->toDateString();
        }

        return $jours;
    }

    /** Variation en %, arrondie ; null sans base de comparaison. */
    public static function variation(float|int $actuel, float|int $precedent): ?int
    {
        return $precedent > 0 ? (int) round(($actuel - $precedent) / $precedent * 100) : null;
    }
}
