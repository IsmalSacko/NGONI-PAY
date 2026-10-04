<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lot;
use App\Models\Produit;
use App\Support\Quantite;

/**
 * Lots et péremption : ce qui entre crée un lot (numéro, date), ce qui sort
 * se prend dans le lot qui périme le plus tôt (« premier périmé, premier
 * sorti »). Le stock de l'article reste la référence ; une part sans lot
 * (stock d'avant, inventaire) se vend après les lots.
 */
class Lots
{
    public function entrer(Produit $produit, int|float $quantite, ?string $numero, ?string $peremption, ?string $achatId = null): ?Lot
    {
        if ($quantite <= 0 || ($numero === null && $peremption === null)) {
            return null;
        }

        return Lot::create([
            'produit_id' => $produit->id,
            'numero' => $numero,
            'peremption' => $peremption,
            'quantite_initiale' => $quantite,
            'quantite' => $quantite,
            'achat_id' => $achatId,
        ]);
    }

    /**
     * Prend `$quantite` dans les lots, le plus proche de sa péremption
     * d'abord. Rend ce qui a été pris, pour le rendre en cas d'annulation.
     *
     * @return list<array{lot_id: string, quantite: int|float}>
     */
    public function prelever(Produit $produit, int|float $quantite): array
    {
        $pris = [];
        $reste = Quantite::normaliser($quantite);
        $lots = Lot::where('produit_id', $produit->id)->where('quantite', '>', 0)
            ->orderByRaw('peremption IS NULL')->orderBy('peremption')->orderBy('created_at')
            ->lockForUpdate()->get();

        foreach ($lots as $lot) {
            if ($reste <= 0) {
                break;
            }
            $prise = Quantite::normaliser(min($reste, $lot->quantite));
            $lot->update(['quantite' => Quantite::normaliser($lot->quantite - $prise)]);
            $pris[] = ['lot_id' => $lot->id, 'quantite' => $prise];
            $reste = Quantite::normaliser($reste - $prise);
        }

        return $pris;
    }

    /** @param  list<array{lot_id: string, quantite: int|float}>|null  $pris */
    public function rendre(?array $pris): void
    {
        foreach ($pris ?? [] as $p) {
            $lot = Lot::whereKey($p['lot_id'])->lockForUpdate()->first();
            $lot?->update(['quantite' => Quantite::normaliser($lot->quantite + $p['quantite'])]);
        }
    }
}
