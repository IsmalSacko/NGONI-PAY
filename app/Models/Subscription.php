<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Abonnement d'une entreprise. Il n'existe pas de plan gratuit :
 * - trial : essai offert une seule fois, à la création (durée réglée sur le plan
 *   `trial` depuis la console) ;
 * - basic / pro : activés après validation d'une demande, ou accordés par l'exploitant.
 * Une fois ends_at passé, l'entreprise ne peut plus encaisser, quel que soit le plan.
 */
class Subscription extends Model
{
    use HasFactory;

    public const PLAN_TRIAL = 'trial';
    public const PLAN_BASIC = 'basic';
    public const PLAN_PRO = 'pro';

    public const PLANS = [self::PLAN_TRIAL, self::PLAN_BASIC, self::PLAN_PRO];

    protected $fillable = [
        'business_id',
        'plan',
        'starts_at',
        'ends_at',
        'is_active',
        'is_manual',
        'granted_by',
        'admin_note',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'is_active' => 'boolean',
        'is_manual' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function grantedBy()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * Démarre l'essai d'une entreprise qui n'a encore jamais eu d'abonnement.
     */
    public static function startTrial(Business $business): self
    {
        $jours = SubscriptionPlan::byCode(self::PLAN_TRIAL)?->trialDays() ?? 7;

        return self::create([
            'business_id' => $business->id,
            'plan' => self::PLAN_TRIAL,
            'is_active' => true,
            'starts_at' => now(),
            'ends_at' => now()->addDays($jours),
        ]);
    }

    /**
     * L'abonnement est-il actif à l'instant présent ?
     * ends_at est inclus (actif jusqu'à la fin de ce jour) ; NULL => à vie.
     */
    public function isCurrentlyActive(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->ends_at === null) {
            return true; // à vie
        }
        return Carbon::parse($this->ends_at)->endOfDay()->greaterThanOrEqualTo(Carbon::now());
    }

    public function isTrial(): bool
    {
        return $this->plan === self::PLAN_TRIAL;
    }

    /**
     * Met fin à l'abonnement immédiatement (révocation par l'exploitant).
     * ends_at = hier : ends_at est inclus, « aujourd'hui » laisserait la journée.
     */
    public function expireNow(): void
    {
        $this->update([
            'is_manual' => false,
            'granted_by' => null,
            'admin_note' => null,
            'ends_at' => now()->subDay(),
        ]);
    }

    /**
     * Compteurs pour l'admin (console web et API) : répartition par plan + actifs/expirés.
     */
    public static function adminSummary(): array
    {
        $total = Business::count();
        $byPlan = self::selectRaw('plan, COUNT(*) as c')->groupBy('plan')->pluck('c', 'plan');

        $active = self::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', today()))
            ->count();

        return [
            'total_businesses' => $total,
            'total_subscriptions' => (int) $byPlan->sum(),
            'trial' => (int) ($byPlan[self::PLAN_TRIAL] ?? 0),
            'basic' => (int) ($byPlan[self::PLAN_BASIC] ?? 0),
            'pro' => (int) ($byPlan[self::PLAN_PRO] ?? 0),
            'active' => $active,
            // Entreprises sans abonnement actif, y compris celles qui n'en ont jamais eu.
            'expired' => $total - $active,
        ];
    }
}
