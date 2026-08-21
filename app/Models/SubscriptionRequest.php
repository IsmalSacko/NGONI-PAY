<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande d'abonnement déposée par un commerçant.
 *
 * Elle n'accorde rien par elle-même : voir {@see \App\Services\SubscriptionRequestService}.
 */
class SubscriptionRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'requested_by_user_id',
        'plan',
        'method',
        'amount_due',
        'currency',
        'months',
        'cycle',
        'note',
        'contact_phone',
        'proof_path',
        'proof_note',
        'status',
        'decided_at',
        'decided_by_user_id',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionRequestStatus::class,
            'cycle' => BillingCycle::class,
            'amount_due' => 'decimal:2',
            'months' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /**
     * Demandes encore à instruire.
     */
    public function scopePending($query)
    {
        return $query->where('status', SubscriptionRequestStatus::Pending);
    }

    /**
     * URL publique de la preuve, ou `null` si le commerçant n'en a pas joint.
     */
    public function proofUrl(): ?string
    {
        return $this->proof_path
            ? url(\Illuminate\Support\Facades\Storage::url($this->proof_path))
            : null;
    }
}
