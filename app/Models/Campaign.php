<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    use HasFactory;

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_EVERY_3_WEEKS = 'every_3_weeks';

    public const FREQUENCY_MONTHLY = 'monthly';

    /** Annonce rédigée depuis la console. */
    public const TYPE_APP_UPDATE = 'app_update';

    /** Campagne dont le contenu vit dans une classe de courriel. */
    public const TYPE_LEGACY = 'legacy';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENT = 'sent';

    public const AUDIENCE_ALL = 'all';
    public const AUDIENCE_SELECTED = 'selected';

    protected $fillable = [
        'key',
        'name',
        'type',
        'subject',
        'message',
        'version',
        'store_url',
        'status',
        'scheduled_at',
        'audience',
        'mailable_class',
        'is_recurring',
        'frequency',
        'next_run_at',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'is_recurring' => 'boolean',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'scheduled_at' => 'datetime',
        ];
    }

    public function sends()
    {
        return $this->hasMany(CampaignSend::class);
    }

    /**
     * Destinataires retenus, quand l'annonce ne s'adresse pas à tout le monde.
     */
    public function targets()
    {
        return $this->belongsToMany(User::class, 'campaign_targets')->withTimestamps();
    }

    /**
     * L'annonce est-elle prête à partir ?
     *
     * Programmée et l'heure venue, ou envoyée sur-le-champ : une annonce dont
     * l'heure n'est pas arrivée ne part pas, même si le planificateur passe.
     */
    public function isDue(): bool
    {
        return $this->status === self::STATUS_SCHEDULED
            && $this->scheduled_at !== null
            && $this->scheduled_at->isPast();
    }

    /**
     * Lien de téléchargement annoncé, ou celui de la configuration à défaut.
     */
    public function downloadUrl(): string
    {
        return $this->store_url ?: (string) config('mobile.store_url');
    }

    public function nextRunFromNow(): \Illuminate\Support\Carbon
    {
        return match ($this->frequency) {
            self::FREQUENCY_WEEKLY => now()->addWeek(),
            self::FREQUENCY_EVERY_3_WEEKS => now()->addWeeks(3),
            self::FREQUENCY_MONTHLY => now()->addMonthNoOverflow(),
            default => now()->addWeek(),
        };
    }
}
