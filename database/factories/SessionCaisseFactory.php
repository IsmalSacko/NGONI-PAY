<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SessionCaisse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionCaisse>
 */
class SessionCaisseFactory extends Factory
{
    protected $model = SessionCaisse::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'fond_initial' => fake()->numberBetween(5000, 20000),
            'statut' => 'ouverte',
            'ouverte_le' => now(),
        ];
    }
}
