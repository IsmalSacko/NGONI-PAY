<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Enums\Country;
use App\Models\User;
use App\Support\Phone\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Retrouve un compte à partir du numéro saisi, pour l'app comme pour le site.
 *
 * Repris de Ngoni Pay, un même numéro peut désigner deux comptes :
 * « 0605758494 » et « +33605758494 ». Le mot de passe les départage ; sans
 * mot de passe (réinitialisation), la forme la plus probable passe d'abord.
 */
class Identification
{
    /**
     * Comptes qui répondent à ce numéro, la forme la plus probable d'abord.
     *
     * @return Collection<int, User>
     */
    public static function comptes(string $telephone, ?string $pays): Collection
    {
        $country = Country::tryFrom(strtoupper((string) $pays)) ?? Country::default();
        $candidats = PhoneNumber::candidates($telephone, $country);

        return User::withoutGlobalScopes()
            ->whereIn('phone', $candidats)
            ->whereNull('deleted_at')
            ->get()
            ->sortBy(fn (User $u) => array_search($u->phone, $candidats, true))
            ->values();
    }

    /** Compte actif dont le mot de passe correspond, ou null. */
    public static function connecter(string $telephone, ?string $pays, string $password): ?User
    {
        return self::comptes($telephone, $pays)
            ->first(fn (User $u) => $u->is_active && Hash::check($password, $u->password));
    }

    /**
     * Trace une connexion refusée et sa cause, pour comprendre les « mot de passe
     * oublié » qui n'en sont pas (mauvais pays, espace en trop, compte désactivé).
     * Jamais le mot de passe : seulement sa longueur et la présence d'espaces.
     */
    public static function tracerRefus(string $telephone, ?string $pays, string $password): void
    {
        $country = Country::tryFrom(strtoupper((string) $pays)) ?? Country::default();
        $comptes = self::comptes($telephone, $pays);
        $actifs = $comptes->filter(fn (User $u) => $u->is_active);

        $raison = match (true) {
            $comptes->isEmpty() => 'aucun compte pour ce numéro dans ce pays',
            $actifs->isEmpty() => 'compte désactivé',
            $actifs->contains(fn (User $u) => Hash::check(trim($password), $u->password)) => 'espaces autour du mot de passe',
            default => 'mot de passe différent',
        };

        Log::warning('Connexion refusée : '.$raison, [
            'telephone' => $telephone,
            'pays' => $country->value,
            'candidats' => PhoneNumber::candidates($telephone, $country),
            'comptes' => $comptes->map(fn (User $u) => $u->phone.($u->is_active ? '' : ' (inactif)'))->all(),
            'longueur_mot_de_passe' => mb_strlen($password),
        ]);
    }
}
