<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'client_id',
        'user_id',
        'amount',
        'currency',
        'method',
        'provider',
        'provider_reference',
        'provider_checkout_url',
        'transaction_ref',
        'idempotency_key',
        'status',
        'purpose',
        'paid_at',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /* ================= RELATIONS ================= */

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    public function callbacks()
    {
        return $this->hasMany(PaymentCallback::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }
}
