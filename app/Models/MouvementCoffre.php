<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un mouvement d'un coffre : ajout ou retrait à la main, ou versement du soir. */
#[Fillable(['boutique_id', 'coffre_id', 'user_id', 'type', 'montant', 'jour', 'base', 'jour_automatique'])]
class MouvementCoffre extends Model
{
    use BelongsToBoutique, HasUuids;

    public const AJOUT = 'ajout';

    public const RETRAIT = 'retrait';

    public const AUTOMATIQUE = 'automatique';

    protected $table = 'mouvements_coffre';

    protected function casts(): array
    {
        return ['montant' => 'integer', 'base' => 'integer', 'jour' => 'date', 'jour_automatique' => 'date'];
    }

    /** Ce que le mouvement change au solde : un retrait le diminue. */
    public function signe(): int
    {
        return $this->type === self::RETRAIT ? -$this->montant : $this->montant;
    }

    /** @return BelongsTo<Coffre, $this> */
    public function coffre(): BelongsTo
    {
        return $this->belongsTo(Coffre::class);
    }

    /** @return BelongsTo<User, $this> */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
