<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Un essai gratuit par téléphone.
 *
 * Chaque téléphone utilisé par un propriétaire de boutique est noté par son
 * empreinte. Une inscription depuis un téléphone déjà noté pour un autre compte
 * n'obtient pas d'essai : se réinscrire avec un autre numéro ne redonne plus
 * 7 jours. Les employés ne comptent pas — le téléphone de la boutique, prêté au
 * caissier, ne prive personne de son essai.
 */
class EssaisAppareils
{
    /** Ce téléphone a déjà servi à un autre propriétaire. */
    public static function dejaUtilise(?string $empreinte, ?User $sauf = null): bool
    {
        return $empreinte !== null && DB::table('essais_appareils')
            ->where('empreinte', $empreinte)
            ->when($sauf !== null, fn ($q) => $q->where('user_id', '!=', $sauf->id))
            ->exists();
    }

    /**
     * Les autres comptes déjà vus sur ce téléphone, pour l'alerte à l'exploitant.
     *
     * @return list<array{nom: string, telephone: ?string, inscrit_le: ?string}>
     */
    public static function autresComptes(?string $empreinte, User $sauf): array
    {
        if ($empreinte === null) {
            return [];
        }

        return User::withTrashed()
            ->whereIn('id', DB::table('essais_appareils')->where('empreinte', $empreinte)->where('user_id', '!=', $sauf->id)->select('user_id'))
            ->orderBy('created_at')->limit(5)->get(['name', 'phone', 'created_at'])
            ->map(fn (User $u) => ['nom' => (string) $u->name, 'telephone' => $u->phone, 'inscrit_le' => $u->created_at?->format('d/m/Y')])->all();
    }

    /** Note le téléphone d'un propriétaire (sans effet pour un employé ou sans empreinte). */
    public static function noter(?string $empreinte, User $user): void
    {
        if ($empreinte === null || ! Boutique::withoutGlobalScopes()->where('proprietaire_id', $user->id)->exists()) {
            return;
        }

        DB::table('essais_appareils')->insertOrIgnore(['empreinte' => $empreinte, 'user_id' => $user->id, 'created_at' => now()]);
    }
}
