<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'action',
        'old_status',
        'new_status',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
