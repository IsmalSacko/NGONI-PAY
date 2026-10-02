<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Acceptation des conditions d'utilisation : à l'inscription (case à cocher)
 * et, à chaque nouvelle version, à la connexion suivante. La preuve est
 * gardée ligne par ligne dans acceptations_conditions.
 */
class ConditionsUtilisation
{
    public static function version(): string
    {
        return (string) config('conditions.version');
    }

    public function aAccepte(User $user): bool
    {
        return $user->conditions_version === self::version();
    }

    /** @param  'inscription'|'connexion'|'web'  $source */
    public function accepter(User $user, Request $request, string $source): void
    {
        DB::transaction(function () use ($user, $request, $source): void {
            DB::table('acceptations_conditions')->insert([
                'user_id' => $user->id,
                'version' => self::version(),
                'source' => $source,
                'ip' => $request->ip(),
                'appareil' => Str::limit((string) $request->userAgent(), 250, ''),
                'acceptee_le' => now(),
            ]);
            $user->forceFill(['conditions_version' => self::version()])->save();
        });
    }

    /** Ce que l'application reçoit avec le compte (/api/moi). */
    public function etat(User $user): array
    {
        return [
            'version' => self::version(),
            'acceptee' => $this->aAccepte($user),
            'url_conditions' => route('conditions'),
            'url_confidentialite' => route('confidentialite'),
        ];
    }
}
