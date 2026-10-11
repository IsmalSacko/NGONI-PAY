<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un revendeur et son grossiste : demandé, accepté (catalogue visible) ou refusé. */
class LiaisonGrossiste extends Model
{
    protected $table = 'liaisons_grossistes';

    protected $fillable = ['boutique_revendeur_id', 'boutique_grossiste_id', 'statut'];

    /** @return BelongsTo<Boutique, $this> */
    public function revendeur(): BelongsTo
    {
        return $this->belongsTo(Boutique::class, 'boutique_revendeur_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<Boutique, $this> */
    public function grossiste(): BelongsTo
    {
        return $this->belongsTo(Boutique::class, 'boutique_grossiste_id')->withoutGlobalScopes();
    }
}
