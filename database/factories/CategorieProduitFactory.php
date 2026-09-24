<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CategorieProduit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategorieProduit>
 */
class CategorieProduitFactory extends Factory
{
    protected $model = CategorieProduit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nom' => fake()->unique()->word(),
            'couleur' => fake()->hexColor(),
            'ordre' => fake()->numberBetween(0, 10),
        ];
    }
}
