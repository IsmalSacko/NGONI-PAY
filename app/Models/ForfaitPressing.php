<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Forfait d'un client : N pièces payées d'avance, valables jusqu'à une date, déduites à chaque dépôt. */
#[Fillable(['boutique_id', 'client_id', 'vente_id', 'libelle', 'pieces', 'pieces_utilisees', 'prix', 'debut', 'fin'])]
class ForfaitPressing extends Model
{
    use BelongsToBoutique, HasUuids;

    protected $table = 'forfaits_pressing';

    protected function casts(): array
    {
        return ['pieces' => 'integer', 'pieces_utilisees' => 'integer', 'prix' => 'integer', 'debut' => 'date', 'fin' => 'date'];
    }

    public function restantes(): int
    {
        return max(0, $this->pieces - $this->pieces_utilisees);
    }

    public function actif(): bool
    {
        return $this->restantes() > 0 && ! $this->fin->endOfDay()->isPast();
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }
}
