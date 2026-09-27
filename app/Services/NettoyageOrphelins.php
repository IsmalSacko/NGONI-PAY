<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Restes de boutiques « fantômes » : boutiques effacées de la base sans leurs
 * données (clés étrangères contournées). Ventes, articles, clients… orphelins
 * faussent les chiffres de la plateforme sans servir à personne.
 *
 * Les comptes ne sont pas supprimés : ils sont détachés de la boutique fantôme
 * (et rattachés à une autre boutique où ils ont un rôle, s'il y en a une).
 */
class NettoyageOrphelins
{
    /** Tables à inspecter pour trouver les boutiques disparues. */
    private const TABLES = [...SuppressionCompte::ORDRE_EFFACEMENT, 'users', 'roles', 'model_has_roles', 'model_has_permissions'];

    /** Restes de comptes effacés de la base : effacés avec les boutiques fantômes. */
    private const PAR_COMPTE = ['abonnements', 'demandes_abonnement', 'notifications_app', 'appareils', 'password_reset_codes', 'annonce_cibles', 'sessions'];

    /**
     * Lignes qui pointent vers un compte qui n'existe plus, par table.
     *
     * @return array<string, int>
     */
    public function restesDeComptes(): array
    {
        $comptes = DB::table('users')->select('id');
        $morph = (new \App\Models\User)->getMorphClass();

        return collect(self::PAR_COMPTE)
            ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->whereNotNull('user_id')->whereNotIn('user_id', $comptes)->count()])
            ->put('model_has_roles', DB::table('model_has_roles')->where('model_type', $morph)->whereNotIn('model_id', $comptes)->count())
            ->put('personal_access_tokens', DB::table('personal_access_tokens')->where('tokenable_type', $morph)->whereNotIn('tokenable_id', $comptes)->count())
            ->filter()->all();
    }

    /**
     * Ventes (etc.) d'un compte disparu dans une boutique qui existe : gardées,
     * c'est l'historique de la boutique. Signalées seulement.
     *
     * @return array<string, int>
     */
    public function historiqueGarde(): array
    {
        return collect(['ventes', 'mouvements_stock', 'sessions_caisse'])
            ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->whereNotIn('user_id', DB::table('users')->select('id'))
                ->whereIn('boutique_id', DB::table('boutiques')->select('id'))->count()])
            ->filter()->all();
    }

    /** @return list<string> */
    public function fantomes(): array
    {
        return collect(self::TABLES)
            ->flatMap(fn (string $t) => DB::table($t)->whereNotNull('boutique_id')
                ->whereNotIn('boutique_id', DB::table('boutiques')->select('id'))->distinct()->pluck('boutique_id'))
            ->map(fn ($id) => (string) $id)->unique()->sort()->values()->all();
    }

    /**
     * @return list<array{boutique: string, compte: array<string, int>, comptes: list<string>}>
     */
    public function apercu(): array
    {
        return array_map(fn (string $id) => [
            'boutique' => $id,
            'compte' => collect(SuppressionCompte::ORDRE_EFFACEMENT)
                ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->where('boutique_id', $id)->count()])
                ->filter()->all(),
            'comptes' => DB::table('users')->where('boutique_id', $id)->get()->map(fn ($u) => "{$u->name} · {$u->phone}")->all(),
        ], $this->fantomes());
    }

    /** @return array{boutiques: int, lignes: int, sauvegarde: string} */
    public function nettoyer(bool $avecBoutiques = true): array
    {
        // Sans les boutiques : seulement les restes de comptes effacés.
        $ids = $avecBoutiques ? $this->fantomes() : [];
        if ($ids === [] && $this->restesDeComptes() === []) {
            return ['boutiques' => 0, 'lignes' => 0, 'sauvegarde' => ''];
        }
        $comptes = DB::table('users')->select('id');
        $morph = (new \App\Models\User)->getMorphClass();

        // Copie complète avant d'effacer, comme pour une suppression de compte.
        $tables = [];
        foreach ([...SuppressionCompte::ORDRE_EFFACEMENT, 'roles', 'model_has_roles', 'model_has_permissions'] as $t) {
            $tables[$t] = DB::table($t)->whereIn('boutique_id', $ids)->get()->map(fn ($l) => (array) $l)->all();
        }
        $tables['lignes_vente'] = DB::table('lignes_vente')->whereIn('vente_id', DB::table('ventes')->whereIn('boutique_id', $ids)->select('id'))->get()->map(fn ($l) => (array) $l)->all();
        $tables['lignes_achat'] = DB::table('lignes_achat')->whereIn('achat_id', DB::table('achats')->whereIn('boutique_id', $ids)->select('id'))->get()->map(fn ($l) => (array) $l)->all();
        foreach (self::PAR_COMPTE as $t) {
            $tables["{$t}_sans_compte"] = DB::table($t)->whereNotNull('user_id')->whereNotIn('user_id', $comptes)->get()->map(fn ($l) => (array) $l)->all();
        }
        $tables['model_has_roles_sans_compte'] = DB::table('model_has_roles')->where('model_type', $morph)->whereNotIn('model_id', $comptes)->get()->map(fn ($l) => (array) $l)->all();
        $tables['users_detaches'] = DB::table('users')->whereIn('boutique_id', $ids)->get(['id', 'name', 'phone', 'boutique_id'])->map(fn ($l) => (array) $l)->all();

        $chemin = 'suppressions/'.now()->format('Y-m-d-His').'-orphelins.json';
        Storage::disk('local')->put($chemin, (string) json_encode(['nettoye_le' => now()->toIso8601String(), 'boutiques_fantomes' => $ids, 'tables' => $tables], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $lignes = array_sum(array_map('count', $tables));

        $preuves = DB::table('demandes_abonnement')->whereNotNull('user_id')->whereNotIn('user_id', $comptes)->pluck('preuve_chemin')->filter()->values()->all();
        $photos = DB::table('produits')->whereIn('boutique_id', $ids)->pluck('photo')->filter()->values()->all();

        DB::transaction(function () use ($ids, $comptes, $morph): void {
            foreach (self::PAR_COMPTE as $t) {
                DB::table($t)->whereNotNull('user_id')->whereNotIn('user_id', $comptes)->delete();
            }
            DB::table('model_has_roles')->where('model_type', $morph)->whereNotIn('model_id', $comptes)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', $morph)->whereNotIn('tokenable_id', $comptes)->delete();

            // Comptes : vers une autre boutique où ils ont un rôle, sinon sans boutique.
            foreach (DB::table('users')->whereIn('boutique_id', $ids)->get(['id']) as $u) {
                $autre = DB::table('model_has_roles')->where('model_id', $u->id)->whereNotIn('boutique_id', $ids)
                    ->whereIn('boutique_id', DB::table('boutiques')->select('id'))->value('boutique_id');
                DB::table('users')->where('id', $u->id)->update(['boutique_id' => $autre]);
            }
            foreach (SuppressionCompte::ORDRE_EFFACEMENT as $table) {
                DB::table($table)->whereIn('boutique_id', $ids)->delete();
            }
            DB::table('model_has_roles')->whereIn('boutique_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('boutique_id', $ids)->delete();
            DB::table('roles')->whereIn('boutique_id', $ids)->delete();
        });

        Storage::disk('local')->delete([...$photos, ...$preuves]);
        Log::warning('Restes de boutiques fantômes nettoyés', ['boutiques' => $ids, 'lignes' => $lignes, 'sauvegarde' => $chemin]);

        return ['boutiques' => count($ids), 'lignes' => $lignes, 'sauvegarde' => $chemin];
    }
}
