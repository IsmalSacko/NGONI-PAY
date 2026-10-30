<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L'arrêté du transfert d'argent : l'argent du tiroir et chaque puce comptés,
 * à côté de ce que l'application attendait. Les écarts deviennent des
 * ajustements ; les opérations d'avant ne se modifient plus.
 */
#[Fillable(['user_id', 'especes_theorique', 'especes_compte', 'puces'])]
class ArreteTransfert extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $table = 'arretes_transfert';

    protected function casts(): array
    {
        return ['especes_theorique' => 'integer', 'especes_compte' => 'integer', 'puces' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
