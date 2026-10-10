<?php

declare(strict_types=1);

namespace App\Services\Console;

use App\Enums\Country;
use App\Models\Abonnement;
use App\Models\Boutique;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Comptes de la console (propriétaires et leur abonnement) : filtres, tris et
 * compteurs, les mêmes pour la console web et celle de l'application.
 */
class ComptesConsole
{
    public const STATUTS = [
        '' => 'Tous',
        'essai' => 'Essais en cours',
        'payants' => 'Abonnés payants',
        'fin_proche' => 'Fin sous 7 jours',
        'expires' => 'Expirés',
    ];

    public const USAGES = [
        '' => 'Tout usage',
        'vend' => 'Ont vendu cette semaine',
        'jamais_vendu' => 'Jamais vendu',
        'dormants' => 'App non ouverte depuis 14 jours',
    ];

    public const TRIS = [
        'recents' => 'Derniers inscrits',
        'activite' => 'Dernière activité',
        'ventes' => 'Plus de ventes',
        'ca' => 'Plus gros chiffre (30 jours)',
        'fin' => 'Fin d’abonnement la plus proche',
        'nom' => 'Nom',
    ];

    /**
     * @param  array{recherche?: ?string, statut?: ?string, plan?: ?string, pays?: ?string, activite?: ?string, usage?: ?string, tri?: ?string}  $f
     * @return Builder<Abonnement>
     */
    public function requete(array $f): Builder
    {
        $boutiques = fn () => Boutique::withoutGlobalScopes()->select('id')->whereColumn('proprietaire_id', 'abonnements.user_id');
        $ventes = fn () => DB::table('ventes')->where('statut', 'validee')->whereIn('boutique_id', $boutiques());
        $enCours = fn (Builder $q) => $q->where('est_actif', true)->where(fn ($w) => $w->whereNull('fin')->orWhereDate('fin', '>=', today()));

        $q = Abonnement::query()->avecCompte()
            ->select('abonnements.*')
            ->addSelect([
                'inscrit_le' => DB::table('users')->select('created_at')->whereColumn('users.id', 'abonnements.user_id'),
                'nom_compte' => DB::table('users')->select('name')->whereColumn('users.id', 'abonnements.user_id'),
                'derniere_app' => DB::table('personal_access_tokens')->selectRaw('MAX(last_used_at)')->whereColumn('tokenable_id', 'abonnements.user_id'),
                'nb_ventes' => $ventes()->selectRaw('COUNT(*)'),
                'ca_30j' => $ventes()->where('created_at', '>=', now()->subDays(30))->selectRaw('COALESCE(SUM(total), 0)'),
            ]);

        if (filled($f['recherche'] ?? null)) {
            $q->whereHas('proprietaire', fn ($u) => $u->recherche((string) $f['recherche']));
        }

        match ($f['statut'] ?? '') {
            // « actifs » : l'ancien filtre des applications déjà installées.
            'actifs' => $enCours($q),
            'essai' => $enCours($q->where('plan', 'essai')),
            'payants' => $enCours($q->where('plan', '!=', 'essai')),
            'fin_proche' => $enCours($q)->whereNotNull('fin')->whereDate('fin', '<=', today()->addDays(7)),
            'expires' => $q->where(fn ($w) => $w->where('est_actif', false)->orWhereDate('fin', '<', today())),
            default => null,
        };

        if (filled($f['plan'] ?? null)) {
            $q->where('plan', $f['plan']);
        }
        foreach (['pays' => 'pays', 'activite' => 'activite'] as $cle => $colonne) {
            if (filled($f[$cle] ?? null)) {
                $q->whereIn('user_id', Boutique::withoutGlobalScopes()->where($colonne, $f[$cle])->whereNotNull('proprietaire_id')->select('proprietaire_id'));
            }
        }

        match ($f['usage'] ?? '') {
            'vend' => $q->whereExists($ventes()->where('created_at', '>=', now()->subDays(7))->selectRaw('1')),
            'jamais_vendu' => $q->whereNotExists($ventes()->selectRaw('1')),
            'dormants' => $q->whereNotExists(DB::table('personal_access_tokens')->whereColumn('tokenable_id', 'abonnements.user_id')->where('last_used_at', '>=', now()->subDays(14))->selectRaw('1')),
            default => null,
        };

        // Les comptes sans valeur (jamais ouvert l'app, pas de fin) passent en dernier.
        match ($f['tri'] ?? 'recents') {
            'activite' => $q->orderByRaw('derniere_app IS NULL, derniere_app DESC'),
            'ventes' => $q->orderByDesc('nb_ventes'),
            'ca' => $q->orderByDesc('ca_30j'),
            'fin' => $q->orderByRaw('fin IS NULL, fin ASC'),
            'nom' => $q->orderBy('nom_compte'),
            default => $q->orderByDesc('inscrit_le'),
        };

        return $q;
    }

    /** Nombre de comptes par statut, pour les puces de la console. @return array<string, int> */
    public function compteurs(): array
    {
        $base = fn () => Abonnement::query()->avecCompte();
        $enCours = fn (Builder $q) => $q->where('est_actif', true)->where(fn ($w) => $w->whereNull('fin')->orWhereDate('fin', '>=', today()));

        return [
            '' => $base()->count(),
            'essai' => $enCours($base()->where('plan', 'essai'))->count(),
            'payants' => $enCours($base()->where('plan', '!=', 'essai'))->count(),
            'fin_proche' => $enCours($base())->whereNotNull('fin')->whereDate('fin', '<=', today()->addDays(7))->count(),
            'expires' => $base()->where(fn ($w) => $w->where('est_actif', false)->orWhereDate('fin', '<', today()))->count(),
        ];
    }

    /** Pays où il y a des boutiques, le plus fréquent d'abord. @return array<string, string> code => libellé */
    public function pays(): array
    {
        return Boutique::withoutGlobalScopes()->whereNotNull('pays')
            ->selectRaw('pays, COUNT(*) n')->groupBy('pays')->orderByDesc('n')->pluck('pays')
            ->mapWithKeys(fn (string $c) => [$c => Country::tryFrom($c)?->label() ?? $c])->all();
    }
}
