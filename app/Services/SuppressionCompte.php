<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Suppression définitive d'un compte et de ses boutiques, par l'exploitant.
 *
 * - Le compte, les boutiques dont il est propriétaire et tout leur contenu
 *   (articles, ventes, clients, stock, caisse, achats…), ses abonnement,
 *   demandes, notifications et téléphones.
 * - Les employés de ces boutiques partent avec elles, sauf ceux qui travaillent
 *   aussi ailleurs : ceux-là sont conservés et rattachés à leur autre boutique.
 * - Refusée si le compte a des ventes (ou mouvements, séances) dans la boutique
 *   d'un autre commerçant : l'historique de cet autre commerçant serait perdu.
 *
 * Avant d'effacer, une copie complète des lignes supprimées est écrite dans
 * storage/app/suppressions/ : de quoi restaurer en cas d'erreur.
 */
class SuppressionCompte
{
    /** Tables rattachées à une boutique (effacées avec elle par la base). */
    private const PAR_BOUTIQUE = [
        'categories_produits', 'produits', 'clients', 'mouvements_stock', 'sessions_caisse', 'ventes',
        'reglements_credit', 'fournisseurs', 'achats', 'paiements_fournisseur', 'clotures',
    ];

    /** Tables rattachées à un compte (effacées avec lui par la base). */
    private const PAR_COMPTE = ['abonnements', 'demandes_abonnement', 'notifications_app', 'appareils', 'password_reset_codes', 'annonce_cibles'];

    /**
     * Ce que la suppression emporterait, sans rien toucher.
     *
     * @return array{boutiques: list<string>, comptes_supprimes: list<string>, comptes_conserves: list<string>, articles: int, ventes: int, clients: int, blocage: ?string}
     */
    public function apercu(User $compte): array
    {
        $plan = $this->plan($compte);

        return [
            'boutiques' => Boutique::withoutGlobalScopes()->whereIn('id', $plan['boutiques'])->orderBy('nom')->pluck('nom')->all(),
            'comptes_supprimes' => User::whereIn('id', $plan['supprimes'])->orderBy('name')->get()->map(fn (User $u) => "{$u->name} · {$u->phone}")->all(),
            'comptes_conserves' => User::whereIn('id', array_keys($plan['conserves']))->orderBy('name')->get()->map(fn (User $u) => "{$u->name} · {$u->phone}")->all(),
            'articles' => DB::table('produits')->whereIn('boutique_id', $plan['boutiques'])->count(),
            'ventes' => DB::table('ventes')->whereIn('boutique_id', $plan['boutiques'])->count(),
            'clients' => DB::table('clients')->whereIn('boutique_id', $plan['boutiques'])->count(),
            'blocage' => $this->blocage($plan),
        ];
    }

    /**
     * @return array{boutiques: int, comptes: int, sauvegarde: string}
     */
    public function supprimer(User $compte, User $exploitant): array
    {
        if ($compte->id === $exploitant->id || $compte->est_admin_plateforme) {
            throw ValidationException::withMessages(['compte' => ['Compte protégé : un administrateur de la plateforme ne peut pas être supprimé ici.']]);
        }

        $plan = $this->plan($compte);
        if ($raison = $this->blocage($plan)) {
            throw ValidationException::withMessages(['compte' => [$raison]]);
        }

        $sauvegarde = $this->sauvegarder($compte, $plan, $exploitant);
        $fichiers = $this->fichiers($plan);

        DB::transaction(function () use ($plan): void {
            // Employés conservés : ils quittent ces boutiques pour leur autre boutique.
            foreach ($plan['conserves'] as $id => $autreBoutique) {
                DB::table('users')->where('id', $id)->whereIn('boutique_id', $plan['boutiques'])->update(['boutique_id' => $autreBoutique]);
            }

            // Rôles et accès de ces boutiques (sans clé étrangère : à effacer soi-même).
            DB::table(config('permission.table_names.model_has_roles'))->whereIn('boutique_id', $plan['boutiques'])->delete();
            DB::table(config('permission.table_names.model_has_permissions'))->whereIn('boutique_id', $plan['boutiques'])->delete();
            DB::table(config('permission.table_names.model_has_roles'))->where('model_type', (new User)->getMorphClass())->whereIn('model_id', $plan['supprimes'])->delete();
            DB::table(config('permission.table_names.roles'))->whereIn('boutique_id', $plan['boutiques'])->delete();

            // D'abord ce qui pointe vers un compte sans cascade (ventes, stock,
            // séances) : MySQL n'efface pas les tables filles d'une boutique dans
            // un ordre garanti, et un compte effacé avant ses ventes bloquerait tout.
            DB::table('ventes')->whereIn('boutique_id', $plan['boutiques'])->delete();
            DB::table('mouvements_stock')->whereIn('boutique_id', $plan['boutiques'])->delete();
            DB::table('sessions_caisse')->whereIn('boutique_id', $plan['boutiques'])->delete();

            // Les boutiques, et avec elles tout leur contenu (cascade de la base).
            DB::table('boutiques')->whereIn('id', $plan['boutiques'])->delete();

            // Les comptes, leurs connexions, et ce qui leur est rattaché (cascade).
            DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())->whereIn('tokenable_id', $plan['supprimes'])->delete();
            DB::table('sessions')->whereIn('user_id', $plan['supprimes'])->delete();
            DB::table('users')->whereIn('id', $plan['supprimes'])->delete();
        });

        // Photos, logos et preuves de paiement, une fois la base à jour.
        Storage::disk('local')->delete($fichiers);

        Log::warning('Compte supprimé par l’exploitant', [
            'compte' => $compte->id, 'nom' => $compte->name, 'telephone' => $compte->phone,
            'boutiques' => $plan['boutiques'], 'comptes' => $plan['supprimes'], 'par' => $exploitant->id, 'sauvegarde' => $sauvegarde,
        ]);

        return ['boutiques' => count($plan['boutiques']), 'comptes' => count($plan['supprimes']), 'sauvegarde' => $sauvegarde];
    }

    /**
     * Boutiques à effacer, comptes à effacer, employés conservés (id → autre boutique).
     *
     * @return array{boutiques: list<string>, supprimes: list<string>, conserves: array<string, string>}
     */
    private function plan(User $compte): array
    {
        $boutiques = Boutique::withoutGlobalScopes()->where('proprietaire_id', $compte->id)->pluck('id')->map(fn ($id) => (string) $id)->all();

        $membres = collect(DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', $compte->getMorphClass())->whereIn('boutique_id', $boutiques)->pluck('model_id'))
            ->merge(DB::table('users')->whereIn('boutique_id', $boutiques)->pluck('id'))
            ->map(fn ($id) => (string) $id)->unique()->reject(fn ($id) => $id === (string) $compte->id);

        $supprimes = [(string) $compte->id];
        $conserves = [];
        foreach (User::whereIn('id', $membres)->get() as $membre) {
            $ailleurs = array_values(array_diff($membre->boutiqueIds(), $boutiques));
            if ($membre->est_admin_plateforme || $ailleurs !== []) {
                $conserves[(string) $membre->id] = $ailleurs[0] ?? null;
            } else {
                $supprimes[] = (string) $membre->id;
            }
        }

        return ['boutiques' => $boutiques, 'supprimes' => $supprimes, 'conserves' => array_filter($conserves)];
    }

    /** Activité d'un compte à effacer dans la boutique d'un autre : on ne l'efface pas. */
    private function blocage(array $plan): ?string
    {
        foreach (['ventes' => 'des ventes', 'mouvements_stock' => 'des mouvements de stock', 'sessions_caisse' => 'des séances de caisse'] as $table => $quoi) {
            $autre = DB::table($table)->whereIn('user_id', $plan['supprimes'])->whereNotIn('boutique_id', $plan['boutiques'])->value('boutique_id');
            if ($autre !== null) {
                $nom = Boutique::withoutGlobalScopes()->whereKey($autre)->value('nom') ?? 'une autre boutique';

                return "Impossible : ce compte (ou un de ses employés) a {$quoi} dans « {$nom} », qui n’est pas à lui. "
                    .'Le supprimer effacerait l’historique de cette boutique. Désactivez plutôt le compte.';
            }
        }

        return null;
    }

    /** Copie complète des lignes qui vont disparaître, pour pouvoir les restaurer. */
    private function sauvegarder(User $compte, array $plan, User $exploitant): string
    {
        $lignes = fn ($requete) => $requete->get()->map(fn ($l) => (array) $l)->all();
        $donnees = [
            'supprime_le' => now()->toIso8601String(),
            'par' => ['id' => $exploitant->id, 'nom' => $exploitant->name],
            'compte' => ['id' => $compte->id, 'nom' => $compte->name, 'telephone' => $compte->phone],
            'employes_conserves' => $plan['conserves'],
            'tables' => [
                'boutiques' => $lignes(DB::table('boutiques')->whereIn('id', $plan['boutiques'])),
                'users' => $lignes(DB::table('users')->whereIn('id', $plan['supprimes'])),
                'roles' => $lignes(DB::table(config('permission.table_names.roles'))->whereIn('boutique_id', $plan['boutiques'])),
                'model_has_roles' => $lignes(DB::table(config('permission.table_names.model_has_roles'))->where(fn ($q) => $q
                    ->whereIn('boutique_id', $plan['boutiques'])->orWhereIn('model_id', $plan['supprimes']))),
            ],
        ];
        foreach (self::PAR_BOUTIQUE as $table) {
            $donnees['tables'][$table] = $lignes(DB::table($table)->whereIn('boutique_id', $plan['boutiques']));
        }
        foreach (self::PAR_COMPTE as $table) {
            $donnees['tables'][$table] = $lignes(DB::table($table)->whereIn('user_id', $plan['supprimes']));
        }
        foreach (['lignes_vente' => 'vente_id', 'lignes_achat' => 'achat_id'] as $table => $cle) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                $parent = $cle === 'vente_id' ? 'ventes' : 'achats';
                $donnees['tables'][$table] = $lignes(DB::table($table)->whereIn($cle, DB::table($parent)->whereIn('boutique_id', $plan['boutiques'])->select('id')));
            }
        }

        $chemin = 'suppressions/'.now()->format('Y-m-d-His').'-'.$compte->id.'.json';
        Storage::disk('local')->put($chemin, (string) json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $chemin;
    }

    /** @return list<string> */
    private function fichiers(array $plan): array
    {
        return array_values(array_filter([
            ...DB::table('boutiques')->whereIn('id', $plan['boutiques'])->pluck('logo')->all(),
            ...DB::table('produits')->whereIn('boutique_id', $plan['boutiques'])->pluck('photo')->all(),
            ...DB::table('demandes_abonnement')->whereIn('user_id', $plan['supprimes'])->pluck('preuve_chemin')->all(),
        ]));
    }
}
