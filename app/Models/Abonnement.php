<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abonnement d'un compte propriétaire : il couvre toutes ses boutiques.
 *
 * Actif jusqu'à la fin du jour `fin` inclus ; `fin` NULL = sans échéance.
 * Expiré : tout reste consultable, plus rien ne se fait (voir le middleware
 * `abonnement`).
 */
class Abonnement extends Model
{
    protected $fillable = [
        'user_id', 'plan', 'debut', 'fin', 'est_actif', 'est_manuel', 'accorde_par', 'note_admin', 'rappel_fin_pour', 'jours_offerts', 'fonctions_accordees',
        // L'abonnement Apple qui le paie (originalTransactionId) : seule sa fin chez Apple l'arrête.
        'apple_transaction_originale',
    ];

    protected function casts(): array
    {
        return [
            'debut' => 'date',
            'fin' => 'date',
            'rappel_fin_pour' => 'date',
            'est_actif' => 'boolean',
            'est_manuel' => 'boolean',
            'fonctions_accordees' => 'array',
        ];
    }

    /**
     * Abonnements dont le compte existe encore : la console n'affiche ni ne
     * compte ceux d'un compte effacé de la base.
     *
     * @param  Builder<Abonnement>  $query
     */
    public function scopeAvecCompte(Builder $query): void
    {
        $query->whereHas('proprietaire');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function proprietaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function estEnCours(): bool
    {
        if (! $this->est_actif) {
            return false;
        }

        return $this->fin === null || self::finA($this->fin)->isFuture();
    }

    /** L'heure où un abonnement ou un essai s'arrête, le jour de sa fin. */
    public const HEURE_FIN = 16;

    /** Le moment exact de la fin : le jour [fin], à 16 h (heure de Bamako, celle du serveur). */
    public static function finA(mixed $fin): Carbon
    {
        return Carbon::parse($fin)->setTime(self::HEURE_FIN, 0);
    }

    /**
     * Le premier jour de fin encore en cours, pour les requêtes : aujourd'hui
     * avant 16 h, demain ensuite (`fin >= ce jour`).
     */
    public static function premierJourEnCours(): Carbon
    {
        return now()->lt(today()->setTime(self::HEURE_FIN, 0)) ? today() : today()->addDay();
    }

    public function estEssai(): bool
    {
        return $this->plan === Plan::ESSAI;
    }

    public function planModele(): ?Plan
    {
        return Plan::parCode($this->plan);
    }

    /**
     * Fonctions accordées à la main par l'exploitant, en plus de la formule
     * (seulement celles que la formule n'inclut pas).
     *
     * @return list<string>
     */
    public function fonctionsAccordees(): array
    {
        return array_values(array_intersect(array_keys(Plan::FONCTIONNALITES), (array) ($this->fonctions_accordees ?? [])));
    }

    /** La formule inclut cette fonction, ou l'exploitant l'a accordée. */
    public function inclut(string $fonctionnalite): bool
    {
        return ($this->planModele()?->inclut($fonctionnalite) ?? false)
            || in_array($fonctionnalite, $this->fonctionsAccordees(), true);
    }

    /**
     * Les fonctions ouvertes par cet abonnement : celles de la formule et
     * celles accordées à la main.
     *
     * @return list<string>
     */
    public function fonctionnalites(): array
    {
        return array_values(array_unique([...($this->planModele()?->fonctionnalitesIncluses() ?? []), ...$this->fonctionsAccordees()]));
    }

    /** Fin immédiate (révocation) : « hier », car `fin` est incluse. */
    public function expirerMaintenant(): void
    {
        $this->update([
            'fin' => now()->subDay()->toDateString(),
            'est_manuel' => false,
            'accorde_par' => null,
            'note_admin' => null,
        ]);
    }
}
