<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Boutique;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Boutique>
 */
class BoutiqueFactory extends Factory
{
    protected $model = Boutique::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nom' => fake()->company(),
            'pays' => 'ML',
            'devise' => 'XOF',
            'telephone' => fake()->unique()->numerify('+2237#######'),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
