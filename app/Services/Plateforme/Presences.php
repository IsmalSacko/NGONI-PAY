<?php

declare(strict_types=1);

namespace App\Services\Plateforme;

use App\Models\User;
use App\Support\Periode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Qui utilise l'application, et d'où : la dernière activité notée par
 * NoterPresence, rattachée à la boutique où elle a eu lieu.
 *
 * Les comptes de l'exploitant sont écartés : ils ne disent rien de l'usage
 * des commerçants.
 */
class Presences
{
    /**
     * L'application interroge le serveur chaque minute tant qu'elle est
     * ouverte : trois minutes de silence, c'est qu'elle ne l'est plus.
     */
    public const EN_LIGNE_MINUTES = 3;

    /**
     * Une application à jour se signale toutes les 15 s, et annonce son départ
     * (arrière-plan, déconnexion). Sans signal depuis 40 s — téléphone éteint,
     * réseau coupé —, elle n'est plus tenue pour ouverte.
     */
    public const PRESENCE_SECONDES = 40;

    /**
     * Les derniers vus, du plus récent au plus ancien.
     *
     * @return Collection<int, object{nom: string, boutique: ?string, pays: ?string, vu_le: CarbonInterface, en_ligne: bool, plateforme: ?string, modele: ?string}>
     */
    public function recents(int $combien = 8): Collection
    {
        return $this->commercants()
            ->whereNotNull('users.vu_le')
            ->orderByDesc('users.vu_le')
            ->limit($combien)
            ->get(['users.name', 'users.vu_le', 'users.presence_jusqua', 'users.vu_plateforme', 'users.vu_modele', 'b.nom as boutique', 'b.pays'])
            ->map(fn (User $u) => (object) [
                'nom' => $u->name,
                'boutique' => $u->boutique,
                'pays' => $u->pays,
                'vu_le' => $u->vu_le,
                'presence_jusqua' => $u->presence_jusqua,
                'en_ligne' => self::estEnLigne($u->presence_jusqua),
                'plateforme' => $u->vu_plateforme,
                'modele' => $u->vu_modele,
            ]);
    }

    /** Applications ouvertes en ce moment : seules celles qui se signalent comptent. */
    public function nombreEnLigne(): int
    {
        return $this->commercants()->where('users.presence_jusqua', '>', now())->count();
    }

    /**
     * Vus ces trois dernières minutes avec une application d'avant le signal
     * de présence : actifs, sans qu'on puisse dire s'ils le sont encore.
     */
    public function nombreActifsSansSignal(): int
    {
        return $this->commercants()
            ->whereNull('users.presence_jusqua')
            ->where('users.vu_le', '>=', now()->subMinutes(self::EN_LIGNE_MINUTES))
            ->count();
    }

    /** Utilisateurs vus depuis le début de la période. */
    public function actifs(Periode $periode): int
    {
        return $this->commercants()->where('users.vu_le', '>=', $periode->debut)->count();
    }

    public function total(): int
    {
        return $this->commercants()->count();
    }

    /**
     * Plateforme des utilisateurs vus sur la période, la plus répandue d'abord.
     *
     * @return Collection<string, int> [plateforme ou 'inconnu' => nombre]
     */
    public function parAppareil(Periode $periode): Collection
    {
        return $this->commercants()
            ->where('users.vu_le', '>=', $periode->debut)
            ->groupBy('users.vu_plateforme')
            ->get(['users.vu_plateforme', DB::raw('COUNT(*) as n')])
            ->mapWithKeys(fn ($l) => [$l->vu_plateforme ?? 'inconnu' => (int) $l->n])
            ->sortDesc();
    }

    /**
     * Pays des boutiques où les utilisateurs de la période ont été vus.
     *
     * @return Collection<string, int> [code pays => nombre d'utilisateurs]
     */
    public function parPays(Periode $periode): Collection
    {
        return $this->commercants()
            ->where('users.vu_le', '>=', $periode->debut)
            ->whereNotNull('b.pays')
            ->groupBy('b.pays')
            ->get(['b.pays', DB::raw('COUNT(*) as n')])
            ->mapWithKeys(fn ($l) => [(string) $l->pays => (int) $l->n])
            ->sortDesc();
    }

    /**
     * Ouverte en ce moment : son dernier signal n'a pas expiré, et elle n'a
     * pas annoncé son départ. Une application sans signal n'est jamais « en
     * ligne » : on ne sait que quand elle a été vue.
     */
    public static function estEnLigne(?CarbonInterface $presenceJusqua): bool
    {
        return $presenceJusqua !== null && $presenceJusqua->isFuture();
    }

    /** « En ligne maintenant », « il y a 12 min », « il y a 1 h 12 », « il y a 3 j », puis la date. */
    public static function depuis(?CarbonInterface $vuLe, ?CarbonInterface $presenceJusqua = null): string
    {
        if ($vuLe === null) {
            return 'Jamais vu';
        }
        if (self::estEnLigne($presenceJusqua)) {
            return 'En ligne maintenant';
        }

        $minutes = (int) $vuLe->diffInMinutes(now());

        return match (true) {
            $minutes < 1 => 'En ligne il y a moins d’1 min',
            $minutes < 60 => "En ligne il y a {$minutes} min",
            $minutes < 24 * 60 => sprintf('En ligne il y a %d h %02d', intdiv($minutes, 60), $minutes % 60),
            $minutes < 30 * 24 * 60 => 'En ligne il y a '.intdiv($minutes, 24 * 60).' j',
            default => 'Vu le '.$vuLe->translatedFormat('j M Y'),
        };
    }

    /**
     * Comptes des commerçants, avec la boutique de leur dernière activité
     * (à défaut, leur boutique par défaut) sous l'alias `b`.
     *
     * @return Builder<User>
     */
    private function commercants(): Builder
    {
        return User::query()
            ->where('users.est_admin_plateforme', false)
            ->leftJoin('boutiques as b', 'b.id', '=', DB::raw('COALESCE(users.vu_boutique_id, users.boutique_id)'));
    }
}
