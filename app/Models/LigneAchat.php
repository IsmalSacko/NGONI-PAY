<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LigneAchat extends Model
{
    use HasUuids;

    protected $table = 'lignes_achat';

    protected $fillable = ['produit_id', 'nom_produit', 'quantite', 'prix_achat', 'total_ligne'];
}
