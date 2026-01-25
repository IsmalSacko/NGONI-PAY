<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'invoice_number',
        'total_amount',
        'pdf_path',
        'sent_via',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
