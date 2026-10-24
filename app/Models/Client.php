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

#[Fillable(['nom', 'telephone', 'email', 'notes', 'points_fidelite', 'revendeur', 'plafond_credit', 'code_client', 'niu', 'adresse'])]
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
    protected function casts(): array
    {
        return ['revendeur' => 'boolean'];
    }

    public function scopeAvecSoldeDu($query)
    {
        return $query
            // Ce qui reste dû sur chaque vente (paiement partiel ou crédit total).
            ->withSum(['ventes as credit_total' => fn ($q) => $q->valides()], 'reste_du')
            ->withSum('reglements as reglements_total', 'montant');
    }

    /**
     * Ce qui reste dû sur chacune de ses ventes à crédit : ses règlements
     * soldent d'abord les plus anciennes. Pour l'affichage (« Soldé »,
     * « Reste 12 000 F ») ; la dette du client reste [soldeDu].
     *
     * @return array<string, int> par identifiant de vente
     */
    public function restesParVente(): array
    {
        $disponible = (int) $this->reglements()->sum('montant');
        $restes = [];
        foreach ($this->ventes()->valides()->where('reste_du', '>', 0)->oldest()->orderBy('numero')->get(['id', 'reste_du']) as $v) {
            $couvert = min($disponible, (int) $v->reste_du);
            $restes[$v->id] = (int) $v->reste_du - $couvert;
            $disponible -= $couvert;
        }

        return $restes;
    }

    /**
     * Le solde dans les deux sens : positif, le client doit ; négatif, la
     * boutique lui garde de l'argent (avoir : de la monnaie gardée, une avance).
     * Un achat à crédit se déduit de lui-même de cet avoir.
     */
    public function solde(): int
    {
        return (int) $this->ventes()->valides()->sum('reste_du') - (int) $this->reglements()->sum('montant');
    }

    public function soldeDu(): int
    {
        return max(0, $this->solde());
    }

    /** Ce que la boutique garde pour lui. */
    public function avoir(): int
    {
        return max(0, -$this->solde());
    }
}
