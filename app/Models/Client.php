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

#[Fillable(['nom', 'telephone', 'email', 'notes', 'points_fidelite'])]
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
}
