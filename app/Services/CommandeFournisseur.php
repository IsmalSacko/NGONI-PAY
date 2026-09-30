<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Fournisseur;
use App\Models\Produit;
use Illuminate\Support\Facades\DB;

/**
 * Ce qu'il faut recommander, et à qui : les articles au seuil d'alerte ou en
 * rupture, regroupés par le fournisseur de leur dernier achat — celui à qui le
 * commerçant enverra la liste sur WhatsApp.
 *
 * La quantité proposée couvre un mois de ventes (celles des trente derniers
 * jours), et au moins deux fois le seuil d'alerte : un article qui ne s'est pas
 * vendu récemment ne doit pas être commandé à l'unité. Le commerçant corrige
 * ensuite le message avant de l'envoyer.
 */
class CommandeFournisseur
{
    public const JOURS_COUVERTS = 30;

    /**
     * @return list<array{fournisseur: array{id: string, nom: string, telephone: ?string}|null, articles: list<array{produit_id: string, nom: string, stock: int, seuil: int, quantite: int}>}>
     */
    public function aCommander(): array
    {
        // Articles de la boutique active (portée tenant du modèle) : la table
        // des lignes, lue sans modèle, n'a pas cette portée.
        $actifs = Produit::where('actif', true)->orderBy('nom')->get();
        if ($actifs->isEmpty()) {
            return [];
        }

        $vendus = DB::table('lignes_vente as lv')
            ->join('ventes as v', 'v.id', '=', 'lv.vente_id')
            ->whereIn('lv.produit_id', $actifs->pluck('id'))
            ->where('v.statut', 'validee')
            ->where('v.jour_affaire', '>=', today()->subDays(self::JOURS_COUVERTS - 1)->toDateString())
            ->groupBy('lv.produit_id')
            ->selectRaw('lv.produit_id, SUM(lv.quantite) as vendus')
            ->pluck('vendus', 'produit_id');

        // Au seuil d'alerte, ou à moins d'une semaine de stock au rythme récent
        // (les « À racheter » du pilotage) : la même liste des deux côtés.
        $produits = $actifs
            ->filter(fn (Produit $p) => $p->stock <= $p->seuil_alerte || $this->manqueSousPeu($p, (int) ($vendus[$p->id] ?? 0)))
            ->values();
        if ($produits->isEmpty()) {
            return [];
        }
        $ids = $produits->pluck('id')->all();

        // Fournisseur du dernier achat de chaque article.
        $derniers = DB::table('lignes_achat as la')
            ->join('achats as a', 'a.id', '=', 'la.achat_id')
            ->whereIn('la.produit_id', $ids)
            ->whereNotNull('a.fournisseur_id')
            ->orderByDesc('a.created_at')
            ->get(['la.produit_id', 'a.fournisseur_id'])
            ->unique('produit_id')
            ->pluck('fournisseur_id', 'produit_id');
        $fournisseurs = Fournisseur::whereIn('id', $derniers->values()->unique())->get()->keyBy('id');

        $groupes = [];
        foreach ($produits as $p) {
            $cible = max((int) ($vendus[$p->id] ?? 0), 2 * (int) $p->seuil_alerte, 1);
            $fournisseur = $fournisseurs[$derniers[$p->id] ?? ''] ?? null;
            $cle = $fournisseur?->id ?? '';

            $groupes[$cle] ??= [
                'fournisseur' => $fournisseur === null ? null : ['id' => $fournisseur->id, 'nom' => $fournisseur->nom, 'telephone' => $fournisseur->telephone],
                'articles' => [],
            ];
            $groupes[$cle]['articles'][] = [
                'produit_id' => $p->id,
                'nom' => $p->nom,
                'stock' => (int) $p->stock,
                'seuil' => (int) $p->seuil_alerte,
                'quantite' => max($cible - (int) $p->stock, 1),
            ];
        }

        // Fournisseurs connus d'abord (par nom), les articles sans fournisseur à la fin.
        uasort($groupes, fn ($a, $b) => [$a['fournisseur'] === null, $a['fournisseur']['nom'] ?? ''] <=> [$b['fournisseur'] === null, $b['fournisseur']['nom'] ?? '']);

        return array_values($groupes);
    }

    /** Moins de `Statistiques::JOURS_COUVERTURE_MIN` jours de stock au rythme des trente derniers. */
    private function manqueSousPeu(Produit $p, int $vendus): bool
    {
        if ($vendus === 0 || $p->stock <= 0) {
            return false;
        }

        return floor($p->stock / ($vendus / Statistiques::JOURS_VITESSE)) < Statistiques::JOURS_COUVERTURE_MIN;
    }
}
