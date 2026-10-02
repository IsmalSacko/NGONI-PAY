<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\Presence\Appareil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Acceptation des conditions d'utilisation : à l'inscription (case à cocher),
 * puis à chaque nouvelle version, à la connexion suivante (application ou
 * back-office web). La preuve est gardée ligne par ligne dans
 * acceptations_conditions, et le texte exact de chaque version acceptée dans
 * versions_conditions.
 */
class ConditionsUtilisation
{
    /** Première version de l'application qui sait afficher l'écran d'acceptation. */
    public const VERSION_APPLICATION = '4.9.0';

    public static function version(): string
    {
        return (string) config('conditions.version');
    }

    public function aAccepte(User $user): bool
    {
        return $user->conditions_version === self::version();
    }

    /**
     * L'appareil qui fait la requête sait-il présenter les conditions ? Le
     * back-office web et l'application à partir de 4.9.0, oui ; une
     * application plus ancienne, non (elle sera mise à jour d'office).
     */
    public static function clientSaitAccepter(Request $request): bool
    {
        $version = trim((string) $request->header(Appareil::EN_TETE_VERSION));

        return $version !== '' && version_compare($version, self::VERSION_APPLICATION, '>=');
    }

    /** @param  'inscription'|'connexion'|'web'  $source */
    public function accepter(User $user, Request $request, string $source): void
    {
        $this->archiver();

        DB::transaction(function () use ($user, $request, $source): void {
            DB::table('acceptations_conditions')->insert([
                'user_id' => $user->id,
                // Gardés dans la preuve : elle survit à la suppression du compte.
                'nom' => $user->name,
                'telephone' => $user->phone,
                'version' => self::version(),
                'source' => $source,
                'ip' => $request->ip(),
                'appareil' => self::appareil($request),
                'acceptee_le' => now(),
            ]);
            $user->forceFill(['conditions_version' => self::version()])->save();
        });
    }

    /**
     * « Android · SM-A155F · app 4.9.0 — Dart/3.13 (dart:io) » : ce que
     * l'application dit d'elle-même, puis le client brut.
     */
    public static function appareil(Request $request): string
    {
        $a = Appareil::depuisRequete($request);
        $morceaux = $a->plateforme === null ? [] : array_filter([$a->libelle(), $a->modele, $a->version ? "app {$a->version}" : null]);
        $agent = (string) $request->userAgent();

        return Str::limit(trim(implode(' · ', $morceaux).($morceaux && $agent !== '' ? ' — ' : '').$agent), 250, '');
    }

    /**
     * Copie du texte exact de la version en vigueur (conditions et
     * confidentialité), une fois par version : en cas de litige, on montre ce
     * qui a été accepté, même après une nouvelle version.
     */
    public function archiver(): void
    {
        $version = self::version();
        if (DB::table('versions_conditions')->where('version', $version)->exists()) {
            return;
        }
        DB::table('versions_conditions')->insertOrIgnore([
            'version' => $version,
            'conditions' => view('juridique.conditions')->render(),
            'confidentialite' => view('juridique.confidentialite')->render(),
            'archivee_le' => now(),
        ]);
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

    /**
     * Pour la console : dernière acceptation de chaque compte.
     *
     * @param  list<string>  $userIds
     * @return array<string, array{version: string, acceptee_le: string, appareil: ?string, source: string, a_jour: bool}>
     */
    public function dernieres(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $dernieres = DB::table('acceptations_conditions')->whereIn('user_id', $userIds)
            ->whereIn('id', DB::table('acceptations_conditions')->selectRaw('MAX(id)')->whereIn('user_id', $userIds)->groupBy('user_id'))
            ->get();

        return $dernieres->mapWithKeys(fn ($l) => [(string) $l->user_id => [
            'version' => (string) $l->version,
            'acceptee_le' => \Illuminate\Support\Carbon::parse($l->acceptee_le)->toIso8601String(),
            'appareil' => $l->appareil,
            'source' => (string) $l->source,
            'a_jour' => $l->version === self::version(),
        ]])->all();
    }
}
