<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Retour en arrière après une remise à zéro (ReinitialisationBoutique) : les
 * lignes effacées sont remises en base depuis la sauvegarde, photos comprises.
 *
 * - Jamais refusée pour ce qui a été fait depuis : les anciennes lignes
 *   rejoignent les nouvelles, sans rien écraser (voir fusionner()). Le stock
 *   retiré à la remise à zéro s'ajoute à celui d'aujourd'hui.
 * - Une sauvegarde ne sert qu'une fois. Après plusieurs remises à zéro, on
 *   restaure la plus récente d'abord, puis les précédentes.
 * - Les sauvegardes sont gardées 30 jours (purger(), chaque nuit) : elles
 *   contiennent les noms et téléphones des clients.
 */
class RestaurationBoutique
{
    public const DOSSIER = 'reinitialisations';

    public const DUREE_JOURS = 30;

    /** Ordre de remise en base : une table avant celles qui la référencent. */
    private const ORDRE = [
        'categories_produits', 'produits', 'fournisseurs', 'clients', 'sessions_caisse', 'clotures', 'ventes', 'lignes_vente',
        'mouvements_stock', 'achats', 'lignes_achat', 'paiements_fournisseur', 'reglements_credit',
    ];

    public static function dossierPhotos(string $sauvegarde): string
    {
        return substr($sauvegarde, 0, -strlen('.json'));
    }

    /**
     * Sauvegardes encore restaurables, par boutique, la plus récente d'abord :
     * leurs seuls chemins, lus sur le nom des fichiers (rien n'est ouvert).
     *
     * @return array<string, list<string>>
     */
    public function sauvegardesParBoutique(): array
    {
        $parBoutique = [];
        foreach (Storage::disk('local')->files(self::DOSSIER) as $chemin) {
            // « 2026-10-01-190812-<uuid de la boutique>.json » ; une sauvegarde
            // restaurée devient « ….restauree.json » et sort de la liste.
            if (preg_match('/^\d{4}-\d{2}-\d{2}-\d{6}-([0-9a-f-]{36})\.json$/', basename($chemin), $m)) {
                $parBoutique[$m[1]][] = $chemin;
            }
        }

        return array_map(fn (array $chemins) => collect($chemins)->sortDesc()->values()->all(), $parBoutique);
    }

    /**
     * Sauvegardes restaurables d'une boutique, avec ce qu'elles contiennent.
     *
     * @return list<array<string, mixed>>
     */
    public function sauvegardes(string $boutiqueId): array
    {
        return collect($this->sauvegardesParBoutique()[$boutiqueId] ?? [])->map(fn (string $c) => $this->resume($c))->filter()->values()->all();
    }

    /** @return array{boutique: string, lignes: int} */
    public function restaurer(string $chemin, User $exploitant): array
    {
        $donnees = $this->lire($chemin);
        $boutique = Boutique::withoutGlobalScopes()->findOrFail($donnees['boutique']['id']);

        $depuis = Carbon::parse($donnees['reinitialisee_le']);

        // Une caisse ouverte aujourd'hui : celle qui l'était avant la remise à zéro
        // revient fermée à cette heure-là, plutôt que deux caisses ouvertes à la fois.
        if (DB::table('sessions_caisse')->where('boutique_id', $boutique->id)->where('statut', 'ouverte')->exists()) {
            $donnees['tables']['sessions_caisse'] = array_map(fn (array $r) => $r['statut'] === 'ouverte'
                ? [...$r, 'statut' => 'fermee', 'fermee_le' => $depuis->toDateTimeString()]
                : $r, $donnees['tables']['sessions_caisse'] ?? []);
        }

        $lignes = 0;
        try {
            DB::transaction(function () use ($donnees, $boutique, &$lignes): void {
                $tables = $this->fusionner($donnees['tables'], $boutique->id);
                foreach (self::ORDRE as $table) {
                    $rangs = $tables[$table] ?? [];
                    if ($rangs === []) {
                        continue;
                    }
                    $existants = collect(array_chunk(array_column($rangs, 'id'), 500))
                        ->flatMap(fn ($ids) => DB::table($table)->whereIn('id', $ids)->pluck('id'))->flip();

                    // Articles gardés à la remise à zéro : le stock retiré revient
                    // en plus de ce qui a bougé depuis (ventes, achats, inventaire).
                    if ($table === 'produits') {
                        foreach ($rangs as $r) {
                            if ($existants->has($r['id']) && (int) $r['stock'] !== 0) {
                                DB::table('produits')->where('id', $r['id'])->increment('stock', (int) $r['stock']);
                            }
                        }
                    }

                    $nouveaux = array_values(array_filter($rangs, fn ($r) => ! $existants->has($r['id'])));
                    foreach (array_chunk($nouveaux, 200) as $paquet) {
                        DB::table($table)->insert($paquet);
                    }
                    $lignes += count($nouveaux);
                }
            });
        } catch (QueryException $e) {
            // Ce que la fusion ne prévoit pas (un vendeur supprimé depuis…) :
            // rien n'a été écrit, la transaction est annulée.
            Log::error('Restauration impossible', ['sauvegarde' => $chemin, 'erreur' => $e->getMessage()]);

            throw ValidationException::withMessages(['sauvegarde' => [
                'Restauration impossible : une donnée de la sauvegarde n’a plus sa place (un vendeur supprimé depuis, par exemple). Rien n’a été modifié.',
            ]]);
        }

        $dossier = self::dossierPhotos($chemin);
        foreach (Storage::disk('local')->allFiles($dossier) as $photo) {
            Storage::disk('local')->move($photo, substr($photo, strlen($dossier) + 1));
        }
        Storage::disk('local')->deleteDirectory($dossier);

        // Une sauvegarde ne sert qu'une fois : elle est gardée, sous un autre nom,
        // jusqu'au nettoyage des 30 jours.
        $donnees['restauree_le'] = now()->toIso8601String();
        $donnees['restauree_par'] = ['id' => $exploitant->id, 'nom' => $exploitant->name];
        Storage::disk('local')->put(self::dossierPhotos($chemin).'.restauree.json', (string) json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Storage::disk('local')->delete($chemin);

        Log::warning('Boutique restaurée par l’exploitant', ['boutique' => $boutique->id, 'sauvegarde' => $chemin, 'lignes' => $lignes, 'par' => $exploitant->id]);

        return ['boutique' => $boutique->nom, 'lignes' => $lignes];
    }

    /**
     * Fait une place aux lignes de la sauvegarde parmi celles créées depuis :
     * rien de ce qui existe aujourd'hui n'est modifié, sinon le stock.
     *
     * - Article au même code-barres qu'un article créé depuis : c'est le même,
     *   les deux fusionnent (ventes, achats et stock vont à l'article actuel).
     * - Numéro de ticket ou de clôture déjà pris : l'ancien reprend la suite.
     * - Journée déjà clôturée depuis : la clôture d'essai de ce jour est laissée.
     * - Vente hors ligne déjà renvoyée depuis par un téléphone : gardée une fois.
     *
     * @param  array<string, list<array<string, mixed>>>  $tables
     * @return array<string, list<array<string, mixed>>>
     */
    private function fusionner(array $tables, string $boutiqueId): array
    {
        // Articles : par code-barres.
        $codes = array_values(array_filter(array_column($tables['produits'] ?? [], 'code_barre')));
        $actuels = DB::table('produits')->where('boutique_id', $boutiqueId)->whereIn('code_barre', $codes)->pluck('id', 'code_barre');
        $remplace = [];
        foreach ($tables['produits'] ?? [] as $i => $r) {
            $actuel = $r['code_barre'] !== null ? ($actuels[$r['code_barre']] ?? null) : null;
            if ($actuel !== null && $actuel !== $r['id']) {
                $remplace[$r['id']] = $actuel;
                if ((int) $r['stock'] !== 0) {
                    DB::table('produits')->where('id', $actuel)->increment('stock', (int) $r['stock']);
                }
                unset($tables['produits'][$i]);
            }
        }
        if ($remplace !== []) {
            $tables['produits'] = array_values($tables['produits']);
            foreach (['lignes_vente', 'mouvements_stock', 'lignes_achat'] as $t) {
                $tables[$t] = array_map(fn ($r) => isset($r['produit_id'], $remplace[$r['produit_id']])
                    ? [...$r, 'produit_id' => $remplace[$r['produit_id']]] : $r, $tables[$t] ?? []);
            }
        }

        // Vente hors ligne renvoyée par un téléphone depuis la remise à zéro : c'est
        // la même (même référence), déjà en base avec ses lignes. On ne la double pas.
        $references = array_values(array_filter(array_column($tables['ventes'] ?? [], 'reference_locale')));
        $dejaLa = DB::table('ventes')->where('boutique_id', $boutiqueId)->whereIn('reference_locale', $references)->pluck('reference_locale')->flip();
        $doublons = collect($tables['ventes'] ?? [])->filter(fn ($r) => isset($r['reference_locale']) && $dejaLa->has($r['reference_locale']))->pluck('id')->flip();
        if ($doublons->isNotEmpty()) {
            $tables['ventes'] = array_values(array_filter($tables['ventes'], fn ($r) => ! $doublons->has($r['id'])));
            foreach (['lignes_vente', 'mouvements_stock'] as $t) {
                $tables[$t] = array_values(array_filter($tables[$t] ?? [], fn ($r) => ! isset($r['vente_id']) || ! $doublons->has($r['vente_id'])));
            }
        }

        // Clôtures : une journée clôturée depuis garde sa clôture.
        $jours = DB::table('clotures')->where('boutique_id', $boutiqueId)->pluck('jour_affaire')->map(fn ($j) => substr((string) $j, 0, 10))->flip();
        $tables['clotures'] = array_values(array_filter($tables['clotures'] ?? [], fn ($r) => ! $jours->has(substr((string) $r['jour_affaire'], 0, 10))));

        // Numéros de ticket et de clôture : ceux déjà pris reprennent la suite.
        foreach (['ventes', 'clotures'] as $t) {
            $pris = DB::table($t)->where('boutique_id', $boutiqueId)->pluck('numero')->map(fn ($n) => (int) $n)->flip();
            $suivant = ($pris->keys()->max() ?? 0) + 1;
            $rangs = $tables[$t] ?? [];
            usort($rangs, fn ($a, $b) => (int) $a['numero'] <=> (int) $b['numero']);
            foreach ($rangs as $i => $r) {
                if ($pris->has((int) $r['numero'])) {
                    $rangs[$i]['numero'] = $suivant++;
                }
            }
            $tables[$t] = $rangs;
        }

        return $tables;
    }

    /** Efface les sauvegardes (et leurs photos) de plus de 30 jours. */
    public function purger(): int
    {
        $limite = now()->subDays(self::DUREE_JOURS)->getTimestamp();
        $n = 0;
        foreach (Storage::disk('local')->files(self::DOSSIER) as $chemin) {
            if (str_ends_with($chemin, '.json') && Storage::disk('local')->lastModified($chemin) < $limite) {
                Storage::disk('local')->delete($chemin);
                Storage::disk('local')->deleteDirectory(self::dossierPhotos($chemin));
                $n++;
            }
        }

        return $n;
    }

    /** @return array<string, mixed>|null */
    private function resume(string $chemin): ?array
    {
        $d = json_decode((string) Storage::disk('local')->get($chemin), true);
        if (! is_array($d) || empty($d['reinitialisee_le'])) {
            return null;
        }
        $n = fn (string $t) => count($d['tables'][$t] ?? []);

        return [
            'chemin' => $chemin,
            'le' => Carbon::parse($d['reinitialisee_le']),
            'par' => $d['par']['nom'] ?? '—',
            'expire_le' => Carbon::createFromTimestamp(Storage::disk('local')->lastModified($chemin))->addDays(self::DUREE_JOURS),
            'ventes' => $n('ventes'),
            'clients' => $n('clients'),
            'achats' => $n('achats'),
            'articles' => $n('produits'),
        ];
    }

    /** @return array<string, mixed> */
    private function lire(string $chemin): array
    {
        if (! preg_match('#^'.self::DOSSIER.'/\d{4}-\d{2}-\d{2}-\d{6}-[0-9a-f-]{36}\.json$#', $chemin) || ! Storage::disk('local')->exists($chemin)) {
            throw ValidationException::withMessages(['sauvegarde' => ['Sauvegarde introuvable (plus de 30 jours ?).']]);
        }

        return json_decode((string) Storage::disk('local')->get($chemin), true, flags: JSON_THROW_ON_ERROR);
    }
}
