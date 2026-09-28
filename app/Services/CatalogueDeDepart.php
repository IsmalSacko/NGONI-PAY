<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\CategorieProduit;
use App\Models\Produit;
use App\Support\Money\Currencies;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Deux articles d'exemple, avec leur photo, dans une boutique neuve : la
 * caisse n'est jamais vide au premier lancement, et le guide de démarrage
 * peut faire faire une vraie première vente. Le commerçant les modifie ou
 * les supprime comme les siens.
 *
 * « (exemple) » est dans le nom lui-même : c'est lui que montrent le bouton de
 * caisse, le ticket, le stock et les rapports, et le commerçant voit partout
 * ce qu'il lui reste à renommer.
 */
class CatalogueDeDepart
{
    /** Nom, format, code, prix (franc CFA / devise à centimes), stock, photo. */
    private const ARTICLES = [
        ['Riz (exemple)', '5 kg', 'RIZ', 3500, 450, 20, 'riz.png'],
        ['Huile (exemple)', '1 L', 'HUI', 1500, 250, 24, 'huile.png'],
    ];

    /** À appeler dans le contexte de la boutique (tenant posé). */
    public function installer(Boutique $boutique): void
    {
        try {
            $categorie = CategorieProduit::create(['nom' => 'Alimentation', 'couleur' => '#E3A008']);
            $centimes = Currencies::decimals((string) $boutique->devise) > 0;

            foreach (self::ARTICLES as [$nom, $format, $code, $prixFranc, $prixCentimes, $stock, $photo]) {
                $produit = Produit::create([
                    'categorie_produit_id' => $categorie->id,
                    'nom' => $nom,
                    'format' => $format,
                    'code' => $code,
                    'prix_vente' => $centimes ? $prixCentimes : $prixFranc,
                    'taux_tva' => 0,
                    'stock' => $stock,
                    'seuil_alerte' => 5,
                ]);

                $chemin = "produits/{$produit->id}-depart.png";
                Storage::disk('local')->put($chemin, (string) file_get_contents(resource_path("demarrage/{$photo}")));
                $produit->forceFill(['photo' => $chemin])->save();
            }
        } catch (\Throwable $e) {
            // Une boutique sans exemples reste une boutique utilisable.
            Log::error('Catalogue de départ non installé', ['boutique' => $boutique->id, 'error' => $e->getMessage()]);
        }
    }
}
