<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Abonnement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fusionne deux comptes d'une même personne (hérités de Ngoni Pay :
 * « 0605758494 » et « +33605758494 »). Le compte gardé reçoit les boutiques,
 * les rôles, les ventes, les séances, les mouvements de stock, les demandes et
 * le meilleur des deux abonnements ; l'autre est désactivé, son numéro libéré,
 * puis supprimé (réversible : suppression douce).
 */
class FusionComptes
{
    private const RANG = ['caissier' => 1, 'gerant' => 2, 'admin' => 3];

    /** @return array<string, int|string> résumé de ce qui a été déplacé */
    public function fusionner(User $garde, User $absorbe): array
    {
        if ($garde->is($absorbe)) {
            throw new InvalidArgumentException('Les deux comptes sont identiques.');
        }

        $resume = DB::transaction(function () use ($garde, $absorbe): array {
            $resume = ['roles' => $this->fusionnerRoles($garde, $absorbe)];

            $resume['boutiques'] = DB::table('boutiques')->where('proprietaire_id', $absorbe->id)->update(['proprietaire_id' => $garde->id]);
            foreach (['ventes', 'sessions_caisse', 'mouvements_stock', 'demandes_abonnement'] as $table) {
                $resume[$table] = DB::table($table)->where('user_id', $absorbe->id)->update(['user_id' => $garde->id]);
            }
            DB::table('demandes_abonnement')->where('demande_par', $absorbe->id)->update(['demande_par' => $garde->id]);
            DB::table('demandes_abonnement')->where('decide_par', $absorbe->id)->update(['decide_par' => $garde->id]);
            DB::table('abonnements')->where('accorde_par', $absorbe->id)->update(['accorde_par' => $garde->id]);

            $resume['abonnement'] = $this->garderLeMeilleurAbonnement($garde, $absorbe);

            $garde->forceFill([
                'email' => $garde->email ?: $absorbe->email,
                'est_admin_plateforme' => $garde->est_admin_plateforme || $absorbe->est_admin_plateforme,
                'boutique_id' => $garde->boutique_id ?? $absorbe->boutique_id,
            ])->save();

            $absorbe->tokens()->delete();
            DB::table('password_reset_codes')->where('user_id', $absorbe->id)->delete();
            $absorbe->forceFill([
                'is_active' => false,
                'phone' => 'fusionne-'.$absorbe->id,
                'boutique_id' => null,
            ])->save();
            $absorbe->delete();

            return $resume;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $resume;
    }

    /** Rôles par boutique : le plus fort des deux l'emporte là où les deux en ont un. */
    private function fusionnerRoles(User $garde, User $absorbe): int
    {
        $table = config('permission.table_names.model_has_roles');
        $equipe = config('permission.column_names.team_foreign_key');
        $cle = config('permission.column_names.model_morph_key');
        $type = $garde->getMorphClass();

        $roles = DB::table(config('permission.table_names.roles'))->pluck('name', 'id');
        $deplaces = 0;

        foreach (DB::table($table)->where('model_type', $type)->where($cle, $absorbe->id)->get() as $ligne) {
            $existant = DB::table($table)->where('model_type', $type)->where($cle, $garde->id)->where($equipe, $ligne->{$equipe})->first();

            if ($existant === null) {
                DB::table($table)->where('model_type', $type)->where($cle, $absorbe->id)
                    ->where($equipe, $ligne->{$equipe})->where('role_id', $ligne->role_id)
                    ->update([$cle => $garde->id]);
                $deplaces++;

                continue;
            }

            if ((self::RANG[$roles[$ligne->role_id]] ?? 0) > (self::RANG[$roles[$existant->role_id]] ?? 0)) {
                DB::table($table)->where('model_type', $type)->where($cle, $garde->id)->where($equipe, $ligne->{$equipe})
                    ->update(['role_id' => $ligne->role_id]);
            }
            DB::table($table)->where('model_type', $type)->where($cle, $absorbe->id)->where($equipe, $ligne->{$equipe})->delete();
        }

        return $deplaces;
    }

    private function garderLeMeilleurAbonnement(User $garde, User $absorbe): string
    {
        $a = Abonnement::where('user_id', $garde->id)->first();
        $b = Abonnement::where('user_id', $absorbe->id)->first();

        if ($b === null) {
            return $a?->plan ?? 'aucun';
        }

        if ($a === null || $this->meilleur($b, $a)) {
            $a?->delete();
            $b->update(['user_id' => $garde->id]);

            return $b->plan;
        }

        $b->delete();

        return $a->plan;
    }

    /** En cours avant expiré ; puis la fin la plus lointaine (sans fin = illimité). */
    private function meilleur(Abonnement $x, Abonnement $y): bool
    {
        if ($x->estEnCours() !== $y->estEnCours()) {
            return $x->estEnCours();
        }
        if ($x->fin === null || $y->fin === null) {
            return $x->fin === null && $y->fin !== null;
        }

        return $x->fin->greaterThan($y->fin);
    }
}
