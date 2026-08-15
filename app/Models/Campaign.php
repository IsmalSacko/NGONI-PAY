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

    protected $fillable = [
        'key',
        'name',
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
        ];
    }

    public function sends()
    {
        return $this->hasMany(CampaignSend::class);
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
