<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Remise à zéro d'une boutique dont les données n'étaient que des essais, par
 * son propriétaire ou par l'exploitant : le commerçant repart avec une
 * boutique vierge. La restauration, elle, reste à l'exploitant.
 *
 * - Effacés : ventes et tickets, séances de caisse, clôtures, mouvements de
 *   stock, achats et paiements fournisseurs, clients, crédits et règlements.
 * - Au choix : les fournisseurs ; le catalogue (gardé, il repart d'un stock
 *   à 0 ; sinon articles et catégories partent aussi).
 * - Gardés : la boutique (réglages, logo, programme fidélité), l'équipe et
 *   l'abonnement. La numérotation des tickets repart d'elle-même de 1.
 * - Une caisse restée ouverte est effacée aussi : l'aperçu le signale, sans
 *   bloquer — pour une boutique d'essai, c'est une séance d'essai de plus.
 *
 * Avant d'effacer, une copie des lignes supprimées est écrite dans
 * storage/app/reinitialisations/, photos comprises : de quoi restaurer en cas
 * d'erreur (voir RestaurationBoutique), pendant 30 jours.
 */
class ReinitialisationBoutique
{
    /** Ordre d'effacement : les tables qui en référencent d'autres d'abord. */
    private const TOUJOURS = [
        'ventes', 'mouvements_stock', 'sessions_caisse', 'reglements_credit', 'paiements_fournisseur', 'achats', 'clotures', 'clients',
    ];

    /** Le propriétaire de la boutique, ou l'exploitant de la plateforme : personne d'autre (ni admin employé, ni gérant). */
    public static function autorise(?User $qui, Boutique $boutique): bool
    {
        return $qui !== null && ($qui->est_admin_plateforme || (string) $boutique->proprietaire_id === (string) $qui->id);
    }

    /**
     * Boutiques du compte et ce que leur remise à zéro effacerait, sans rien toucher.
     *
     * @return array{boutiques: list<array<string, mixed>>}
     */
    public function apercu(User $compte): array
    {
        $boutiques = Boutique::withoutGlobalScopes()->where('proprietaire_id', $compte->id)->orderBy('nom')->get(['id', 'nom']);

        return ['boutiques' => $boutiques->map(fn (Boutique $b) => $this->apercuBoutique($b))->values()->all()];
    }

    /**
     * Ce que la remise à zéro d'une boutique effacerait, sans rien toucher.
     *
     * @return array<string, mixed>
     */
    public function apercuBoutique(Boutique $b): array
    {
        return [
            'id' => $b->id,
            'nom' => $b->nom,
            'ventes' => DB::table('ventes')->where('boutique_id', $b->id)->count(),
            'sessions' => DB::table('sessions_caisse')->where('boutique_id', $b->id)->count(),
            'achats' => DB::table('achats')->where('boutique_id', $b->id)->count(),
            'clients' => DB::table('clients')->where('boutique_id', $b->id)->whereNull('deleted_at')->count(),
            'articles' => DB::table('produits')->where('boutique_id', $b->id)->whereNull('deleted_at')->count(),
            'categories' => DB::table('categories_produits')->where('boutique_id', $b->id)->whereNull('deleted_at')->count(),
            'fournisseurs' => DB::table('fournisseurs')->where('boutique_id', $b->id)->whereNull('deleted_at')->count(),
            'blocage' => null,
            'avertissement' => $this->avertissement($b->id),
        ];
    }

    /**
     * @return array{boutique: string, sauvegarde: string}
     */
    public function reinitialiser(string $boutiqueId, User $exploitant, bool $garderCatalogue, bool $garderFournisseurs): array
    {
        $boutique = Boutique::withoutGlobalScopes()->findOrFail($boutiqueId);
        if (! self::autorise($exploitant, $boutique)) {
            throw new AuthorizationException('Seul le propriétaire de la boutique peut la réinitialiser.');
        }

        $tables = [...self::TOUJOURS];
        if (! $garderFournisseurs) {
            $tables[] = 'fournisseurs';
        }
        if (! $garderCatalogue) {
            array_push($tables, 'produits', 'categories_produits');
        }

        $sauvegarde = $this->sauvegarder($boutique, $tables, $exploitant);
        $photos = $garderCatalogue ? [] : array_values(array_filter(DB::table('produits')->where('boutique_id', $boutique->id)->pluck('photo')->all()));

        DB::transaction(function () use ($boutique, $tables, $garderCatalogue): void {
            foreach ($tables as $table) {
                DB::table($table)->where('boutique_id', $boutique->id)->delete();
            }
            if ($garderCatalogue) {
                DB::table('produits')->where('boutique_id', $boutique->id)->update(['stock' => 0, 'updated_at' => now()]);
            }
        });

        // Les photos suivent la sauvegarde : une restauration les remet en place,
        // le nettoyage des 30 jours les efface avec elle.
        $dossier = RestaurationBoutique::dossierPhotos($sauvegarde);
        foreach ($photos as $photo) {
            if (Storage::disk('local')->exists($photo)) {
                Storage::disk('local')->move($photo, $dossier.'/'.$photo);
            }
        }

        Log::warning('Boutique réinitialisée par l’exploitant', [
            'boutique' => $boutique->id, 'nom' => $boutique->nom, 'tables' => $tables, 'par' => $exploitant->id, 'sauvegarde' => $sauvegarde,
        ]);

        return ['boutique' => $boutique->nom, 'sauvegarde' => $sauvegarde];
    }

    private function avertissement(string $boutiqueId): ?string
    {
        return DB::table('sessions_caisse')->where('boutique_id', $boutiqueId)->where('statut', 'ouverte')->exists()
            ? 'Une caisse est encore ouverte : elle sera effacée aussi. Si quelqu’un encaisse en ce moment, prévenez-le avant.'
            : null;
    }

    /** Copie des lignes qui vont disparaître, pour pouvoir les restaurer. */
    private function sauvegarder(Boutique $boutique, array $tables, User $exploitant): string
    {
        $lignes = fn ($requete) => $requete->get()->map(fn ($l) => (array) $l)->all();
        $donnees = [
            'reinitialisee_le' => now()->toIso8601String(),
            'par' => ['id' => $exploitant->id, 'nom' => $exploitant->name],
            'boutique' => ['id' => $boutique->id, 'nom' => $boutique->nom],
            'tables' => [
                // Le stock d'avant, même quand le catalogue est gardé.
                'produits' => $lignes(DB::table('produits')->where('boutique_id', $boutique->id)),
            ],
        ];
        foreach ($tables as $table) {
            $donnees['tables'][$table] ??= $lignes(DB::table($table)->where('boutique_id', $boutique->id));
        }
        foreach (['lignes_vente' => ['vente_id', 'ventes'], 'lignes_achat' => ['achat_id', 'achats']] as $table => [$cle, $parent]) {
            $donnees['tables'][$table] = $lignes(DB::table($table)->whereIn($cle, DB::table($parent)->where('boutique_id', $boutique->id)->select('id')));
        }

        $chemin = 'reinitialisations/'.now()->format('Y-m-d-His').'-'.$boutique->id.'.json';
        Storage::disk('local')->put($chemin, (string) json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $chemin;
    }
}
