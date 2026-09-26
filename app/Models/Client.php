<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['nom', 'telephone', 'email', 'notes', 'points_fidelite'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToBoutique, HasFactory, HasUuids, SoftDeletes;

    /**
     * @return HasMany<Vente, $this>
     */
    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class);
    }

    /**
     * @return HasMany<ReglementCredit, $this>
     */
    public function reglements(): HasMany
    {
        return $this->hasMany(ReglementCredit::class);
    }

    /**
     * Ce que le client doit : ventes validées payées « à crédit », moins ses
     * règlements. Une vente à crédit annulée n'est plus due.
     */
    public function scopeAvecSoldeDu($query)
    {
        return $query
            ->withSum(['ventes as credit_total' => fn ($q) => $q->valides()->where('moyen_paiement', 'credit_client')], 'total')
            ->withSum('reglements as reglements_total', 'montant');
    }

    public function soldeDu(): int
    {
        $credit = (int) $this->ventes()->valides()->where('moyen_paiement', 'credit_client')->sum('total');

        return max(0, $credit - (int) $this->reglements()->sum('montant'));
    }
}
