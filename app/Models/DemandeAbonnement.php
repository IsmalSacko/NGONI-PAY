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
        'moyen', 'note', 'telephone_contact', 'preuve_chemin', 'preuve_note', 'jeko_paiement_id', 'jeko_transaction_id', 'fedapay_transaction_id', 'pawapay_deposit_id', 'frais_mobile',
        'statut', 'decide_le', 'decide_par', 'note_decision', 'parrain_recompense_id', 'recu_numero', 'periode_debut', 'periode_fin',
    ];

    protected function casts(): array
    {
        return [
            'cycle' => CycleFacturation::class,
            'statut' => StatutDemande::class,
            'mois' => 'integer',
            'montant' => 'integer',
            'frais_mobile' => 'integer',
            'decide_le' => 'datetime',
            'periode_debut' => 'date',
            'periode_fin' => 'date',
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
     * Le parrain que cette demande, première payée d'un filleul, a récompensé.
     *
     * @return BelongsTo<User, $this>
     */
    public function parrainRecompense(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parrain_recompense_id');
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

    /** Payée (ou en cours de paiement) en ligne : Jèko ou FedaPay. Elle s'approuve seule. */
    public function paiementEnLigne(): bool
    {
        return $this->jeko_paiement_id !== null || $this->fedapay_transaction_id !== null || $this->pawapay_deposit_id !== null;
    }

    /** « Wave via Jèko », « Airtel Money via FedaPay », ou null. */
    public function libellePaiementEnLigne(): ?string
    {
        if ($this->jeko_paiement_id !== null) {
            return (config('jeko.moyens')[substr((string) $this->moyen, 5)] ?? 'Mobile Money').' via Jèko';
        }
        if ($this->fedapay_transaction_id !== null) {
            $code = substr((string) $this->moyen, 8);
            $moyens = array_merge(config('fedapay.carte'), ...array_values(config('fedapay.moyens_par_pays')));

            return ($moyens[$code] ?? 'paiement en ligne').' via FedaPay';
        }
        if ($this->pawapay_deposit_id !== null) {
            $code = substr((string) $this->moyen, 8);
            $moyens = array_merge(...array_values(array_column(config('pawapay.pays'), 'moyens')));

            return ($moyens[$code] ?? 'Mobile Money').' via pawaPay';
        }

        return null;
    }
}
