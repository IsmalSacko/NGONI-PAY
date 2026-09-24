<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TypeMouvementStock;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MouvementStock>
 */
class MouvementStockFactory extends Factory
{
    protected $model = MouvementStock::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'produit_id' => Produit::factory(),
            'user_id' => User::factory(),
            'type' => TypeMouvementStock::Entree,
            'quantite' => fake()->numberBetween(1, 50),
            'stock_apres' => fake()->numberBetween(0, 500),
        ];
    }
}
