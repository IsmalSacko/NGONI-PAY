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
     * ouverte, et s'arrête dès qu'on la quitte : 90 s de silence, c'est
     * qu'elle ne l'est plus. NoterPresence note chaque passage (au plus une
     * fois toutes les 20 s) pour que la minute suivante ne tombe jamais à côté.
     */
    public const EN_LIGNE_SECONDES = 90;

    /**
     * Pour les consoles d'application qui comptent encore en minutes
     * (GET /api/plateforme/activite) : l'arrondi supérieur de EN_LIGNE_SECONDES.
     */
    public const EN_LIGNE_MINUTES = 2;

    /**
     * Une application à jour se signale toutes les 15 s et annonce son départ
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
                'en_ligne' => self::estEnLigne($u->vu_le, $u->presence_jusqua),
                'en_ligne_jusqua' => self::enLigneJusqua($u->vu_le, $u->presence_jusqua),
                'plateforme' => $u->vu_plateforme,
                'modele' => $u->vu_modele,
            ]);
    }

    public function nombreEnLigne(): int
    {
        return $this->commercants()->where(fn (Builder $q) => $q
            // Une application à jour : son signal n'a pas expiré.
            ->where('users.presence_jusqua', '>', now())
            // Sinon (version d'avant le signal, back-office) : vue depuis 90 s,
            // et après son éventuel départ annoncé.
            ->orWhere(fn (Builder $v) => $v->where('users.vu_le', '>=', now()->subSeconds(self::EN_LIGNE_SECONDES))
                ->where(fn (Builder $d) => $d->whereNull('users.presence_jusqua')->orWhereColumn('users.vu_le', '>', 'users.presence_jusqua')))
        )->count();
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
     * Ouverte en ce moment. Une application à jour l'est jusqu'à l'expiration
     * de son dernier signal, et ne l'est plus dès qu'elle annonce son départ.
     * Une version d'avant le signal (ou le back-office) l'est 90 s après son
     * dernier passage — et seulement s'il est postérieur à un départ annoncé.
     */
    public static function estEnLigne(?CarbonInterface $vuLe, ?CarbonInterface $presenceJusqua = null): bool
    {
        return self::enLigneJusqua($vuLe, $presenceJusqua) !== null;
    }

    /** Jusqu'à quand on la tient pour ouverte ; null si elle ne l'est pas. */
    public static function enLigneJusqua(?CarbonInterface $vuLe, ?CarbonInterface $presenceJusqua = null): ?CarbonInterface
    {
        if ($presenceJusqua !== null && $presenceJusqua->isFuture()) {
            return $presenceJusqua;
        }
        if ($vuLe === null || ($presenceJusqua !== null && ! $vuLe->gt($presenceJusqua))) {
            return null;
        }
        $fin = $vuLe->copy()->addSeconds(self::EN_LIGNE_SECONDES);

        return $fin->isFuture() ? $fin : null;
    }

    /** « En ligne maintenant », « il y a 12 min », « il y a 1 h 12 », « il y a 3 j », puis la date. */
    public static function depuis(?CarbonInterface $vuLe, ?CarbonInterface $presenceJusqua = null): string
    {
        if ($vuLe === null) {
            return 'Jamais vu';
        }
        if (self::estEnLigne($vuLe, $presenceJusqua)) {
            return 'En ligne maintenant';
        }

        $minutes = (int) $vuLe->diffInMinutes(now());

        return match (true) {
            $minutes < 1 => 'En ligne il y a moins d’1 min',
            $minutes < 2 => 'En ligne il y a 1 min',
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
