<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Remboursement, total ou partiel, de ce qu'un client doit (ventes à crédit). */
class ReglementCredit extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $table = 'reglements_credit';

    protected $fillable = ['client_id', 'user_id', 'montant', 'moyen_paiement', 'note'];

    /** @return BelongsTo<User, $this> */
    public function caissier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
