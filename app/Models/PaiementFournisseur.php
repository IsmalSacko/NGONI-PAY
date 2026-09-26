<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaiementFournisseur extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $table = 'paiements_fournisseur';

    protected $fillable = ['fournisseur_id', 'user_id', 'montant', 'moyen_paiement', 'note'];
}
