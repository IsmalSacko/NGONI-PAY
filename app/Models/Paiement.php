<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FournisseurPaiement;
use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'client_id', 'reference_locale', 'fournisseur', 'moyen_paiement', 'statut', 'montant', 'remise',
    'devise_fournisseur', 'montant_fournisseur', 'reference_fournisseur', 'url_paiement',
    'lignes', 'vente_id', 'erreur', 'confirme_le',
])]
class Paiement extends Model
{
    use BelongsToBoutique, HasUuids;

    protected function casts(): array
    {
        return [
            'fournisseur' => FournisseurPaiement::class,
            'moyen_paiement' => MoyenPaiement::class,
            'statut' => StatutPaiement::class,
            'lignes' => 'array',
            'confirme_le' => 'datetime',
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
     * @return BelongsTo<Vente, $this>
     */
    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class);
    }
}
