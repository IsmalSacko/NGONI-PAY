<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notification adressée à un commerçant.
 *
 * Écrite par le serveur au moment de la décision : c'est ce qui manquait pour
 * qu'une approbation ou un refus parvienne à l'intéressé.
 */
class AppNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'business_id',
        'type',
        'title',
        'body',
        'route',
        'read_at',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
}
