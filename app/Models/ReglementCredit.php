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

    protected $fillable = ['client_id', 'user_id', 'session_caisse_id', 'numero', 'montant', 'solde_avant', 'solde_apres', 'moyen_paiement', 'note'];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * Le reçu de paiement : ce qui a été payé, avec quoi, et la dette avant et
     * après (figées au moment du paiement).
     *
     * @return array<string, mixed>
     */
    public function recu(): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'montant' => (int) $this->montant,
            'moyen_paiement' => $this->moyen_paiement,
            'solde_avant' => $this->solde_avant === null ? null : (int) $this->solde_avant,
            'solde_apres' => $this->solde_apres === null ? null : (int) $this->solde_apres,
            'note' => $this->note,
            'date' => $this->created_at,
            'par' => $this->caissier?->name,
            'client' => $this->client?->nom,
            'client_telephone' => $this->client?->telephone,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function caissier(): BelongsTo
    {
        // Un compte supprimé garde son nom sur les tickets, factures et historiques.
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
