<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\Quantite;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Colonne decimal(14,3) lue comme un nombre : entier quand il l'est (12, pas
 * « 12.000 »), sinon à virgule (12.5). Voir {@see Quantite}.
 *
 * @implements CastsAttributes<int|float, mixed>
 */
class QuantiteCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): int|float|null
    {
        return $value === null ? null : Quantite::normaliser($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : number_format(round((float) $value, 3), 3, '.', '');
    }
}
