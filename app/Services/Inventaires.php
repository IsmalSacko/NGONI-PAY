<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventaire;
use App\Models\LigneInventaire;
use App\Models\Produit;
use App\Models\User;
use App\Support\Quantite;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inventaire guidé. On compte article par article, à plusieurs et sans
 * fermer la boutique : chaque comptage garde le stock affiché à ce moment,
 * et la validation déduit ce qui s'est vendu depuis. Chaque écart devient un
 * ajustement (historique de l'article), chiffré au prix d'achat.
 */
class Inventaires
{
    public function __construct(private readonly StockService $stocks) {}

    /** L'inventaire en cours, ou un nouveau. */
    public function enCours(User $auteur): Inventaire
    {
        return Inventaire::where('statut', 'en_cours')->first()
            ?? Inventaire::create(['statut' => 'en_cours', 'cree_par' => $auteur->id]);
    }

    public function compter(Inventaire $inventaire, Produit $produit, float $quantite, User $compteur): LigneInventaire
    {
        $this->exigerEnCours($inventaire);

        return LigneInventaire::updateOrCreate(
            ['inventaire_id' => $inventaire->id, 'produit_id' => $produit->id],
            ['quantite_comptee' => Quantite::normaliser($quantite), 'stock_au_comptage' => $produit->stock, 'compte_par' => $compteur->id],
        );
    }

    public function retirer(Inventaire $inventaire, Produit $produit): void
    {
        $this->exigerEnCours($inventaire);
        LigneInventaire::where('inventaire_id', $inventaire->id)->where('produit_id', $produit->id)->delete();
    }

    /**
     * Écart d'une ligne : ce qu'on a trouvé moins ce que la caisse affichait
     * au comptage. Négatif : il manque.
     */
    public static function ecart(LigneInventaire $ligne): float
    {
        return Quantite::normaliser($ligne->quantite_comptee - $ligne->stock_au_comptage);
    }

    /** Valeur d'un écart, au prix d'achat (à défaut, au prix de vente). */
    public static function valeur(LigneInventaire $ligne): int
    {
        $prix = (int) ($ligne->produit?->prix_achat ?: $ligne->produit?->prix_vente ?: 0);

        return (int) round(self::ecart($ligne) * $prix);
    }

    public function valider(Inventaire $inventaire, User $auteur): Inventaire
    {
        $this->exigerEnCours($inventaire);
        $jour = now()->format('d/m/Y');

        return DB::transaction(function () use ($inventaire, $auteur, $jour): Inventaire {
            $manque = 0;
            $surplus = 0;
            $corriges = 0;
            foreach ($inventaire->lignes()->with('produit')->get() as $ligne) {
                if ($ligne->produit === null) {
                    continue;
                }
                $ecart = self::ecart($ligne);
                if ($ecart == 0) {
                    continue;
                }
                // Les ventes faites depuis le comptage restent comptées : on
                // corrige de l'écart, pas vers le nombre trouvé.
                $actuel = (float) Produit::whereKey($ligne->produit_id)->value('stock');
                $this->stocks->ajuster($ligne->produit, max(0, $actuel + $ecart), $auteur, "Inventaire du {$jour}");
                $valeur = self::valeur($ligne);
                $valeur < 0 ? $manque += -$valeur : $surplus += $valeur;
                $corriges++;
            }
            $inventaire->update([
                'statut' => 'valide', 'valide_par' => $auteur->id, 'valide_le' => now(),
                'valeur_manquante' => $manque, 'valeur_surplus' => $surplus, 'articles_corriges' => $corriges,
            ]);

            return $inventaire;
        });
    }

    public function abandonner(Inventaire $inventaire): void
    {
        $this->exigerEnCours($inventaire);
        $inventaire->update(['statut' => 'abandonne']);
    }

    private function exigerEnCours(Inventaire $inventaire): void
    {
        if ($inventaire->statut !== 'en_cours') {
            throw ValidationException::withMessages(['inventaire' => ['Cet inventaire est terminé.']]);
        }
    }
}
