<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MoyenPaiement;
use App\Models\Concerns\BelongsToBoutique;
use Database\Factories\VenteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'client_id', 'session_caisse_id', 'reference_locale', 'numero', 'sous_total', 'remise',
    'tva', 'total', 'moyen_paiement', 'montant_recu', 'monnaie_rendue', 'statut',
    'vendue_hors_ligne', 'synchronisee_le',
])]
class Vente extends Model
{
    /** @use HasFactory<VenteFactory> */
    use BelongsToBoutique, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'moyen_paiement' => MoyenPaiement::class,
            'vendue_hors_ligne' => 'boolean',
            'synchronisee_le' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function caissier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<SessionCaisse, $this>
     */
    public function sessionCaisse(): BelongsTo
    {
        return $this->belongsTo(SessionCaisse::class);
    }

    /**
     * @return HasMany<LigneVente, $this>
     */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneVente::class);
    }

    public function numeroFormate(): string
    {
        return str_pad((string) $this->numero, 6, '0', STR_PAD_LEFT);
    }
}
