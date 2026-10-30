<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une opération de transfert d'argent : celle d'un client (dépôt, retrait,
 * envoi, facture, crédit), un mouvement des fonds du commerçant, ou l'écart
 * compté à l'arrêté. Elle garde ce qu'elle change à la puce de son opérateur
 * et au tiroir : les soldes en sont la somme.
 */
#[Fillable([
    'user_id', 'operateur_id', 'operateur_nom', 'type', 'montant', 'commission', 'commission_especes', 'via_tiroir',
    'effet_puce', 'effet_especes', 'client_nom', 'client_telephone', 'beneficiaire', 'reference', 'note', 'jour',
    'arrete_id', 'annulee_le', 'annulee_par',
])]
class OperationTransfert extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $table = 'operations_transfert';

    /** Pour un client : il apporte ou reçoit de l'argent liquide. */
    public const CLIENT = ['depot', 'retrait', 'envoi', 'paiement', 'credit'];

    /** Les fonds du commerçant : recharger ou vider une puce, mettre ou prendre des espèces. */
    public const FONDS = ['approvisionnement', 'vidage', 'apport_especes', 'retrait_especes'];

    public const AJUSTEMENT = 'ajustement';

    /** Les types qui touchent une puce, donc demandent un opérateur. */
    public const AVEC_OPERATEUR = ['depot', 'retrait', 'envoi', 'paiement', 'credit', 'approvisionnement', 'vidage'];

    protected function casts(): array
    {
        return [
            'montant' => 'integer', 'commission' => 'integer', 'commission_especes' => 'boolean', 'via_tiroir' => 'boolean',
            'effet_puce' => 'integer', 'effet_especes' => 'integer', 'jour' => 'date', 'annulee_le' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $q */
    public function scopeValides(Builder $q): void
    {
        $q->whereNull('annulee_le');
    }

    public function estAnnulee(): bool
    {
        return $this->annulee_le !== null;
    }

    /** @return BelongsTo<OperateurTransfert, $this> */
    public function operateur(): BelongsTo
    {
        return $this->belongsTo(OperateurTransfert::class, 'operateur_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function annuleurPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annulee_par')->withTrashed();
    }
}
