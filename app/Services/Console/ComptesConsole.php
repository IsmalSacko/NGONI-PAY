<?php

declare(strict_types=1);

namespace App\Services\Console;

use App\Enums\Country;
use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\User;
use App\Support\WhatsApp;
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
        'essai_fin_proche' => 'Essais : fin sous 3 jours',
        'payants' => 'Abonnés payants',
        'fin_proche' => 'Fin sous 7 jours',
        'expires' => 'Expirés',
    ];

    public const USAGES = [
        '' => 'Tout usage',
        'vend' => 'Ont vendu cette semaine',
        'jamais_vendu' => 'Jamais vendu',
        'a_activer' => 'Inscrits depuis 2 jours, jamais vendu',
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
        // Le type de compte d'abord : l'index des jetons commence par lui. Sans
        // lui, MySQL relisait toute la table pour chaque compte (0,9 s la page).
        $jetons = fn () => DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())->whereColumn('tokenable_id', 'abonnements.user_id');

        $q = Abonnement::query()->avecCompte()
            ->select('abonnements.*')
            ->addSelect([
                'inscrit_le' => DB::table('users')->select('created_at')->whereColumn('users.id', 'abonnements.user_id'),
                'nom_compte' => DB::table('users')->select('name')->whereColumn('users.id', 'abonnements.user_id'),
                'derniere_app' => $jetons()->selectRaw('MAX(last_used_at)'),
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
            'essai_fin_proche' => $enCours($q->where('plan', 'essai'))->whereNotNull('fin')->whereDate('fin', '<=', today()->addDays(3)),
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
            'a_activer' => $q->whereNotExists($ventes()->selectRaw('1'))
                ->whereIn('user_id', User::where('created_at', '<=', now()->subDays(2))->select('id')),
            'dormants' => $q->whereNotExists($jetons()->where('last_used_at', '>=', now()->subDays(14))->selectRaw('1')),
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
            'essai_fin_proche' => $enCours($base()->where('plan', 'essai'))->whereNotNull('fin')->whereDate('fin', '<=', today()->addDays(3))->count(),
            'payants' => $enCours($base()->where('plan', '!=', 'essai'))->count(),
            'fin_proche' => $enCours($base())->whereNotNull('fin')->whereDate('fin', '<=', today()->addDays(7))->count(),
            'expires' => $base()->where(fn ($w) => $w->where('est_actif', false)->orWhereDate('fin', '<', today()))->count(),
        ];
    }

    /**
     * Comptes à relancer, pour le tableau de bord : essais qui finissent
     * bientôt, inscrits qui n'ont pas encore vendu.
     *
     * @return array{essais_fin_proche: int, a_activer: int}
     */
    public function aRelancer(): array
    {
        return [
            'essais_fin_proche' => $this->requete(['statut' => 'essai_fin_proche'])->count(),
            'a_activer' => $this->requete(['usage' => 'a_activer'])->count(),
        ];
    }

    /**
     * Relance WhatsApp d'un compte de la liste (`nb_ventes` chargé par
     * requete()) : d'abord la première vente, puis la fin de l'essai.
     *
     * @return array{motif: string, whatsapp: string}|null
     */
    public function relance(Abonnement $a): ?array
    {
        $proprietaire = $a->proprietaire;
        $prenom = trim(explode(' ', trim((string) $proprietaire?->name))[0] ?? '');
        $bonjour = $prenom === '' ? 'Bonjour' : "Bonjour {$prenom}";

        if ((int) $a->nb_ventes === 0 && $proprietaire?->created_at?->lte(now()->subDays(2))) {
            $motif = 'premiere_vente';
            $message = "{$bonjour}, bienvenue sur Ngoni Caisse ! Avez-vous pu faire votre première vente ? "
                .'Si vous voulez, je vous montre en 5 minutes comment ajouter vos articles et encaisser. — L’équipe Ngoni Caisse';
        } elseif ($a->plan === 'essai' && $a->estEnCours() && $a->fin !== null && $a->fin->lte(today()->addDays(3))) {
            $jours = (int) today()->diffInDays($a->fin);
            $quand = match ($jours) {
                0 => 'aujourd’hui',
                1 => 'demain',
                default => "dans {$jours} jours",
            };
            $motif = 'fin_essai';
            $message = "{$bonjour}, votre essai gratuit de Ngoni Caisse se termine {$quand}. "
                .'Avez-vous des questions ? Je peux vous aider à continuer avec la formule qui vous convient. — L’équipe Ngoni Caisse';
        } else {
            return null;
        }

        $lien = WhatsApp::link($proprietaire?->phone, $message);

        return $lien === null ? null : ['motif' => $motif, 'whatsapp' => $lien];
    }

    /** Pays où il y a des boutiques, le plus fréquent d'abord. @return array<string, string> code => libellé */
    public function pays(): array
    {
        return Boutique::withoutGlobalScopes()->whereNotNull('pays')
            ->selectRaw('pays, COUNT(*) n')->groupBy('pays')->orderByDesc('n')->pluck('pays')
            ->mapWithKeys(fn (string $c) => [$c => Country::tryFrom($c)?->label() ?? $c])->all();
    }
}
