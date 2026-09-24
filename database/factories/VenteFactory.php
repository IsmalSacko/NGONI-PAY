<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MoyenPaiement;
use App\Models\User;
use App\Models\Vente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vente>
 */
class VenteFactory extends Factory
{
    protected $model = Vente::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = fake()->numberBetween(500, 20000);

        return [
            'user_id' => User::factory(),
            'numero' => fake()->unique()->numberBetween(1, 999999),
            'sous_total' => $total,
            'remise' => 0,
            'tva' => (int) round($total * 18 / 118),
            'total' => $total,
            'moyen_paiement' => fake()->randomElement(MoyenPaiement::cases()),
            'statut' => 'validee',
        ];
    }
}
