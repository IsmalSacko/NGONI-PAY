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
        'user_id', 'plan', 'debut', 'fin', 'est_actif', 'est_manuel', 'accorde_par', 'note_admin',
    ];

    protected function casts(): array
    {
        return [
            'debut' => 'date',
            'fin' => 'date',
            'est_actif' => 'boolean',
            'est_manuel' => 'boolean',
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

        return $this->fin === null
            || Carbon::parse($this->fin)->endOfDay()->greaterThanOrEqualTo(now());
    }

    public function estEssai(): bool
    {
        return $this->plan === Plan::ESSAI;
    }

    public function planModele(): ?Plan
    {
        return Plan::parCode($this->plan);
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
