<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentCallback extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'provider',
        'payload',
        'signature',
        'received_at',
        'starts_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'starts_at' => 'date',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
