<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Produit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Produit>
 */
class ProduitFactory extends Factory
{
    protected $model = Produit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nom' => fake()->words(2, true),
            'format' => fake()->randomElement(['Paquet 500 g', 'Bouteille 1 L', 'Boîte 1 kg']),
            'code' => strtoupper(fake()->lexify('??')),
            'code_barre' => fake()->unique()->ean13(),
            'prix_vente' => fake()->numberBetween(100, 5000),
            'taux_tva' => 18.00,
            'stock' => fake()->numberBetween(0, 200),
            'seuil_alerte' => 10,
            'actif' => true,
        ];
    }
}
