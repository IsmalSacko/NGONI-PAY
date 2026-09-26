<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Notification affichée dans l'application (cloche). */
class NotificationApp extends Model
{
    protected $table = 'notifications_app';

    protected $fillable = ['user_id', 'annonce_id', 'type', 'titre', 'message', 'lien', 'lue_le'];

    protected function casts(): array
    {
        return ['lue_le' => 'datetime'];
    }
}
