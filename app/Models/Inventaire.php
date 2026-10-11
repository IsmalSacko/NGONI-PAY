<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un inventaire de la boutique : en cours (on compte), validé ou abandonné. */
class Inventaire extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $fillable = ['statut', 'cree_par', 'valide_par', 'valide_le', 'valeur_manquante', 'valeur_surplus', 'articles_corriges'];

    protected function casts(): array
    {
        return ['valide_le' => 'datetime', 'valeur_manquante' => 'integer', 'valeur_surplus' => 'integer', 'articles_corriges' => 'integer'];
    }

    /** @return HasMany<LigneInventaire, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneInventaire::class);
    }

    /** @return BelongsTo<User, $this> */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par')->withTrashed();
    }
}
