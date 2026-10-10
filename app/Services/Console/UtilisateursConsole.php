<?php

declare(strict_types=1);

namespace App\Services\Console;

use App\Models\Boutique;
use App\Models\User;
use App\Services\ConditionsUtilisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Utilisateurs de la console (propriétaires, employés, exploitants) : filtres
 * et tris, les mêmes pour la console web et celle de l'application.
 */
class UtilisateursConsole
{
    public const ROLES = [
        '' => 'Tous les rôles',
        'proprietaires' => 'Propriétaires',
        'employes' => 'Employés',
        'exploitants' => 'Exploitants',
    ];

    public const ETATS = ['' => 'Tous', 'actifs' => 'Actifs', 'desactives' => 'Désactivés'];

    public const APPLIS = [
        '' => 'Toute activité',
        'recents' => 'App ouverte cette semaine',
        'dormants' => 'App non ouverte depuis 14 jours',
        'jamais' => 'Jamais ouvert l’app',
    ];

    /** Conditions d'utilisation : la version en vigueur acceptée, une ancienne, ou jamais. */
    public const CONDITIONS = [
        '' => 'Toutes',
        'a_jour' => 'Acceptées (version actuelle)',
        'ancienne' => 'Ancienne version seulement',
        'jamais' => 'Jamais acceptées',
    ];

    public const TRIS = [
        'recents' => 'Derniers inscrits',
        'activite' => 'Dernière activité',
        'ventes' => 'Plus de ventes',
        'acceptation' => 'Dernière acceptation des conditions',
        'nom' => 'Nom',
    ];

    /**
     * @param  array{recherche?: ?string, role?: ?string, etat?: ?string, pays?: ?string, appli?: ?string, conditions?: ?string, tri?: ?string}  $f
     * @return Builder<User>
     */
    public function requete(array $f): Builder
    {
        $proprietaires = fn () => Boutique::withoutGlobalScopes()->whereNotNull('proprietaire_id')->select('proprietaire_id');
        $jetons = fn () => DB::table('personal_access_tokens')->whereColumn('tokenable_id', 'users.id');
        $acceptations = fn () => DB::table('acceptations_conditions')->whereColumn('acceptations_conditions.user_id', 'users.id');
        $version = ConditionsUtilisation::version();

        $q = User::query()->select('users.*')->addSelect([
            'derniere_app' => $jetons()->selectRaw('MAX(last_used_at)'),
            'derniere_web' => DB::table('sessions')->selectRaw('MAX(last_activity)')->whereColumn('user_id', 'users.id'),
            'nb_appareils' => DB::table('appareils')->selectRaw('COUNT(*)')->whereColumn('user_id', 'users.id'),
            'nb_ventes' => DB::table('ventes')->selectRaw('COUNT(*)')->whereColumn('user_id', 'users.id')->where('statut', 'validee'),
            'derniere_acceptation' => $acceptations()->selectRaw('MAX(acceptee_le)'),
        ])->with('boutique');

        if (filled($f['recherche'] ?? null)) {
            $q->recherche((string) $f['recherche']);
        }

        match ($f['role'] ?? '') {
            'proprietaires' => $q->whereIn('users.id', $proprietaires()),
            'employes' => $q->whereNotNull('boutique_id')->whereNotIn('users.id', $proprietaires())->where('est_admin_plateforme', false),
            'exploitants' => $q->where('est_admin_plateforme', true),
            default => null,
        };

        match ($f['etat'] ?? '') {
            'actifs' => $q->where('is_active', true),
            'desactives' => $q->where('is_active', false),
            default => null,
        };

        if (filled($f['pays'] ?? null)) {
            $q->whereIn('boutique_id', Boutique::withoutGlobalScopes()->where('pays', $f['pays'])->select('id'));
        }

        match ($f['appli'] ?? '') {
            'recents' => $q->whereExists($jetons()->where('last_used_at', '>=', now()->subDays(7))->selectRaw('1')),
            'dormants' => $q->whereExists($jetons()->whereNotNull('last_used_at')->selectRaw('1'))
                ->whereNotExists($jetons()->where('last_used_at', '>=', now()->subDays(14))->selectRaw('1')),
            'jamais' => $q->whereNotExists($jetons()->whereNotNull('last_used_at')->selectRaw('1')),
            default => null,
        };

        match ($f['conditions'] ?? '') {
            'a_jour' => $q->whereExists($acceptations()->where('version', $version)->selectRaw('1')),
            'ancienne' => $q->whereExists($acceptations()->selectRaw('1'))->whereNotExists($acceptations()->where('version', $version)->selectRaw('1')),
            'jamais' => $q->whereNotExists($acceptations()->selectRaw('1')),
            default => null,
        };

        match ($f['tri'] ?? 'recents') {
            'activite' => $q->orderByRaw('derniere_app IS NULL, derniere_app DESC'),
            'acceptation' => $q->orderByRaw('derniere_acceptation IS NULL, derniere_acceptation DESC'),
            'ventes' => $q->orderByDesc('nb_ventes'),
            'nom' => $q->orderBy('name'),
            default => $q->latest('users.created_at'),
        };

        return $q;
    }
}
