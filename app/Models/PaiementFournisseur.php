<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaiementFournisseur extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $table = 'paiements_fournisseur';

    protected $fillable = ['fournisseur_id', 'user_id', 'montant', 'moyen_paiement', 'note', 'boutique_grossiste_id', 'client_grossiste_id', 'statut_grossiste', 'reglement_credit_id'];

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }
}
