<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Réception de marchandise (bon de livraison, facture fournisseur). */
class Achat extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $fillable = ['fournisseur_id', 'user_id', 'reference', 'total', 'note'];

    /** @return HasMany<LigneAchat, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneAchat::class);
    }

    /** @return BelongsTo<Fournisseur, $this> */
    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class);
    }

    /** @return BelongsTo<User, $this> */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
