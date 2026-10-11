<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\QuantiteCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un article compté : ce qu'on a trouvé, et ce que la caisse affichait à ce moment. */
class LigneInventaire extends Model
{
    protected $table = 'lignes_inventaire';

    protected $fillable = ['inventaire_id', 'produit_id', 'quantite_comptee', 'stock_au_comptage', 'compte_par'];

    protected function casts(): array
    {
        return ['quantite_comptee' => QuantiteCast::class, 'stock_au_comptage' => QuantiteCast::class];
    }

    /** @return BelongsTo<Produit, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function compteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'compte_par')->withTrashed();
    }
}
