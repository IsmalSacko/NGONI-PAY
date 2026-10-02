<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Database\Factories\SessionCaisseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'fond_initial', 'fond_final', 'ecart', 'statut', 'ouverte_le', 'fermee_le', 'notes'])]
class SessionCaisse extends Model
{
    /** @use HasFactory<SessionCaisseFactory> */
    use BelongsToBoutique, HasFactory, HasUuids;

    protected $table = 'sessions_caisse';

    protected function casts(): array
    {
        return [
            'ouverte_le' => 'datetime',
            'fermee_le' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function caissier(): BelongsTo
    {
        // Un compte supprimé garde son nom sur les tickets, factures et historiques.
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /**
     * @return HasMany<Vente, $this>
     */
    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class);
    }

    public function estOuverte(): bool
    {
        return $this->statut === 'ouverte';
    }
}
