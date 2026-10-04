<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeMouvementStock;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\User;
use App\Support\Quantite;
use Illuminate\Support\Facades\DB;

/**
 * Ajustement manuel du stock (inventaire, casse, réassort) : le stock change
 * ET le mouvement est journalisé dans la même transaction, jamais l'un sans
 * l'autre — un stock modifié sans trace est impossible à justifier à la
 * fermeture de la caisse.
 */
class StockService
{
    public function ajuster(Produit $produit, int|float|string $nouveauStock, User $auteur, ?string $motif = null): Produit
    {
        return DB::transaction(function () use ($produit, $nouveauStock, $auteur, $motif): Produit {
            // Relit sous verrou : une vente encaissée entre-temps a pu modifier
            // le stock, l'écart doit se calculer sur la valeur à jour.
            $courant = Produit::lockForUpdate()->findOrFail($produit->id);
            $nouveauStock = Quantite::normaliser($nouveauStock);
            $ecart = Quantite::normaliser($nouveauStock - $courant->stock);

            if ($ecart == 0) {
                return $courant;
            }

            $courant->stock = $nouveauStock;
            $courant->save();
            // Une perte (casse, inventaire) sort des lots, le plus proche de sa
            // péremption d'abord ; un surplus reste sans lot.
            if ($ecart < 0) {
                app(Lots::class)->prelever($courant, -$ecart);
            }

            MouvementStock::create([
                'produit_id' => $courant->id,
                'user_id' => $auteur->id,
                'type' => TypeMouvementStock::Ajustement,
                'quantite' => $ecart,
                'stock_apres' => $courant->stock,
                'motif' => $motif ?: 'Ajustement d\'inventaire',
            ]);

            return $courant;
        });
    }
}
