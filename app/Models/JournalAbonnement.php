<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ce que l'exploitant a fait de l'abonnement d'un compte (accord à la main,
 * révocation) et qui l'a fait : l'historique de la fiche du compte.
 */
class JournalAbonnement extends Model
{
    public const ACCORD = 'accord';

    public const REVOCATION = 'revocation';

    protected $table = 'journal_abonnements';

    protected $fillable = [
        'user_id', 'type', 'plan', 'debut', 'fin', 'montant', 'devise', 'moyen', 'reference', 'demande_id', 'par', 'note',
    ];

    protected function casts(): array
    {
        return [
            'debut' => 'date',
            'fin' => 'date',
            'montant' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DemandeAbonnement, $this>
     */
    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandeAbonnement::class, 'demande_id');
    }
}
