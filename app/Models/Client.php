<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'phone',
        'email',
        'notes',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
