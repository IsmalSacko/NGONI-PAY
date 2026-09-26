<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Clôture d'une journée (ticket Z) : chiffres figés, numérotée. */
class Cloture extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $fillable = ['numero', 'jour_affaire', 'user_id', 'totaux'];

    protected function casts(): array
    {
        return ['jour_affaire' => 'date', 'totaux' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
