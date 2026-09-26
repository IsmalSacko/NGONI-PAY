<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fournisseur extends Model
{
    use BelongsToBoutique, HasUuids, SoftDeletes;

    protected $fillable = ['nom', 'telephone', 'notes'];

    /** @return HasMany<Achat, $this> */
    public function achats(): HasMany
    {
        return $this->hasMany(Achat::class);
    }

    /** @return HasMany<PaiementFournisseur, $this> */
    public function paiements(): HasMany
    {
        return $this->hasMany(PaiementFournisseur::class);
    }

    /** Ce que la boutique doit : achats moins paiements. */
    public function scopeAvecSoldeDu($query)
    {
        return $query->withSum('achats as achats_total', 'total')->withSum('paiements as paiements_total', 'montant');
    }

    public function soldeDu(): int
    {
        return max(0, (int) $this->achats()->sum('total') - (int) $this->paiements()->sum('montant'));
    }
}
