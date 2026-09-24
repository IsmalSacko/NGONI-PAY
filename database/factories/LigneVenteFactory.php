<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LigneVente;
use App\Models\Vente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LigneVente>
 */
class LigneVenteFactory extends Factory
{
    protected $model = LigneVente::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $prix = fake()->numberBetween(100, 5000);
        $quantite = fake()->numberBetween(1, 5);

        return [
            'vente_id' => Vente::factory(),
            'nom_produit' => fake()->words(2, true),
            'prix_unitaire' => $prix,
            'taux_tva' => 18.00,
            'quantite' => $quantite,
            'total_ligne' => $prix * $quantite,
        ];
    }
}
