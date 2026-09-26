<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CycleFacturation;
use App\Enums\StatutDemande;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Demande d'abonnement déposée depuis l'application. Le règlement se fait hors
 * application ; l'exploitant approuve une fois le paiement constaté.
 */
class DemandeAbonnement extends Model
{
    protected $table = 'demandes_abonnement';

    protected $fillable = [
        'user_id', 'demande_par', 'boutique_id', 'plan', 'cycle', 'mois', 'montant', 'devise',
        'moyen', 'note', 'telephone_contact', 'preuve_chemin', 'preuve_note',
        'statut', 'decide_le', 'decide_par', 'note_decision',
    ];

    protected function casts(): array
    {
        return [
            'cycle' => CycleFacturation::class,
            'statut' => StatutDemande::class,
            'mois' => 'integer',
            'montant' => 'integer',
            'decide_le' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function proprietaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demande_par');
    }

    /**
     * @return BelongsTo<Boutique, $this>
     */
    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }

    public function scopeEnAttente($query)
    {
        return $query->where('statut', StatutDemande::EnAttente->value);
    }

    /** Preuve stockée hors du disque public : servie via une route authentifiée. */
    public function preuveExiste(): bool
    {
        return $this->preuve_chemin !== null && Storage::disk('local')->exists($this->preuve_chemin);
    }
}
