<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CycleFacturation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanTarif extends Model
{
    protected $fillable = ['plan_id', 'cycle', 'montant', 'devise', 'est_actif'];

    protected function casts(): array
    {
        return [
            'cycle' => CycleFacturation::class,
            'montant' => 'integer',
            'est_actif' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
