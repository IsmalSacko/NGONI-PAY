<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeMouvementStock;
use App\Models\Achat;
use App\Models\Fournisseur;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Réception d'une livraison : chaque article entre en stock (mouvement
 * d'entrée tracé) et prend le nouveau prix d'achat. Le montant payé tout de
 * suite est enregistré ; le reste s'ajoute à ce que l'on doit au fournisseur.
 */
class AchatService
{
    /**
     * @param  list<array{produit_id: string, quantite: int, prix_achat: int}>  $lignes
     */
    public function receptionner(User $auteur, array $lignes, ?string $fournisseurId, ?string $reference, int $montantPaye = 0, string $moyen = 'especes', ?string $note = null): Achat
    {
        return DB::transaction(function () use ($auteur, $lignes, $fournisseurId, $reference, $montantPaye, $moyen, $note): Achat {
            $produits = Produit::whereIn('id', array_column($lignes, 'produit_id'))->lockForUpdate()->get()->keyBy('id');
            $total = 0;
            foreach ($lignes as $l) {
                if (! $produits->has($l['produit_id'])) {
                    throw ValidationException::withMessages(['lignes' => ['Article introuvable.']]);
                }
                $total += $l['quantite'] * $l['prix_achat'];
            }

            if ($montantPaye > $total) {
                throw ValidationException::withMessages(['montant_paye' => ['Le montant payé dépasse le total de la livraison.']]);
            }
            if ($montantPaye < $total && $fournisseurId === null) {
                throw ValidationException::withMessages(['fournisseur_id' => ['Choisissez le fournisseur pour suivre ce qui reste à payer.']]);
            }

            $achat = Achat::create([
                'fournisseur_id' => $fournisseurId,
                'user_id' => $auteur->id,
                'reference' => $reference,
                'total' => $total,
                'note' => $note,
            ]);

            foreach ($lignes as $l) {
                $produit = $produits[$l['produit_id']];
                $achat->lignes()->create([
                    'produit_id' => $produit->id,
                    'nom_produit' => $produit->nom,
                    'quantite' => $l['quantite'],
                    'prix_achat' => $l['prix_achat'],
                    'total_ligne' => $l['quantite'] * $l['prix_achat'],
                ]);

                $produit->stock += $l['quantite'];
                $produit->prix_achat = $l['prix_achat'];
                $produit->save();

                MouvementStock::create([
                    'produit_id' => $produit->id,
                    'user_id' => $auteur->id,
                    'type' => TypeMouvementStock::Entree,
                    'quantite' => $l['quantite'],
                    'stock_apres' => $produit->stock,
                    'motif' => 'Réception'.($reference ? ' '.$reference : ''),
                ]);
            }

            if ($fournisseurId !== null && $montantPaye > 0) {
                Fournisseur::findOrFail($fournisseurId)->paiements()->create([
                    'user_id' => $auteur->id, 'montant' => $montantPaye, 'moyen_paiement' => $moyen,
                    'note' => 'À la réception'.($reference ? ' '.$reference : ''),
                ]);
            }

            return $achat->load('lignes', 'fournisseur');
        });
    }

    public function payer(Fournisseur $fournisseur, User $auteur, int $montant, string $moyen, ?string $note = null): int
    {
        $solde = $fournisseur->soldeDu();
        if ($montant < 1 || $montant > $solde) {
            throw ValidationException::withMessages(['montant' => [$solde > 0 ? 'Montant entre 1 et ce que vous devez.' : 'Vous ne devez rien à ce fournisseur.']]);
        }
        $fournisseur->paiements()->create(['user_id' => $auteur->id, 'montant' => $montant, 'moyen_paiement' => $moyen, 'note' => $note]);

        return $fournisseur->soldeDu();
    }
}
