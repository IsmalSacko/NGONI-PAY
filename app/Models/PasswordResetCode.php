<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetCode extends Model
{
    public const MAX_ATTEMPTS = 5;

    protected $fillable = ['user_id', 'code_hash', 'expires_at', 'attempts'];

    protected $casts = [
        'expires_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
