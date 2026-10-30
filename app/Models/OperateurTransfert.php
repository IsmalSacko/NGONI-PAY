<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Un opérateur de transfert d'argent de la boutique : « Orange Money », « Wave »… */
#[Fillable(['nom', 'ordre'])]
class OperateurTransfert extends Model
{
    use BelongsToBoutique, HasUuids, SoftDeletes;

    protected $table = 'operateurs_transfert';

    /**
     * Proposés au passage en transfert d'argent, selon le pays ; la boutique
     * les renomme, en ajoute ou en retire.
     */
    public const PAR_PAYS = [
        'ML' => ['Orange Money', 'Moov Money', 'Wave'],
        'CI' => ['Orange Money', 'MTN MoMo', 'Moov Money', 'Wave'],
        'SN' => ['Orange Money', 'Wave', 'Free Money'],
        'BF' => ['Orange Money', 'Moov Money', 'Wave'],
        'NE' => ['Airtel Money', 'Moov Money', 'Zamani Cash'],
        'GN' => ['Orange Money', 'MTN MoMo'],
        'CM' => ['Orange Money', 'MTN MoMo'],
        'TG' => ['Flooz', 'T-Money'],
        'BJ' => ['MTN MoMo', 'Moov Money', 'Celtiis Cash'],
    ];

    public const PAR_DEFAUT = ['Orange Money', 'Moov Money', 'Wave'];

    protected function casts(): array
    {
        return ['ordre' => 'integer'];
    }
}
