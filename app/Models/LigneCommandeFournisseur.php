<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une ligne de commande : l'article du revendeur, et celui du grossiste qui le livre. */
class LigneCommandeFournisseur extends Model
{
    public $timestamps = false;

    protected $table = 'lignes_commande_fournisseur';

    protected $fillable = ['commande_id', 'produit_id', 'nom', 'quantite', 'prix_estime', 'produit_grossiste_id', 'quantite_confirmee', 'prix_grossiste'];

    protected function casts(): array
    {
        return ['quantite' => 'float', 'quantite_confirmee' => 'float', 'prix_estime' => 'integer', 'prix_grossiste' => 'integer'];
    }

    /** @return BelongsTo<Produit, $this> */
    public function produitGrossiste(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_grossiste_id')->withoutGlobalScopes();
    }
}
