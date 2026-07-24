<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Subscription extends Model
{
    use HasFactory;

    // Plans disponibles.
    public const PLAN_FREE = 'free';
    public const PLAN_BASIC = 'basic';
    public const PLAN_PRO = 'pro';

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
     * L'abonnement est-il actif à l'instant présent ?
     * ends_at NULL => à vie (jamais expiré).
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

    /**
     * Donne-t-il accès au dashboard pro et à ses fonctionnalités ?
     */
    public function grantsProAccess(): bool
    {
        return $this->isCurrentlyActive()
            && in_array($this->plan, [self::PLAN_BASIC, self::PLAN_PRO], true);
    }
}
