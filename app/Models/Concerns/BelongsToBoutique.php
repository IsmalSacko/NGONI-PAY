<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Boutique;
use App\Models\Scopes\BoutiqueScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rattache une entité métier à une boutique (tenant).
 *
 * - Applique le {@see BoutiqueScope} global : toute requête est filtrée sur
 *   la boutique active.
 * - Renseigne automatiquement `boutique_id` à la création à partir du
 *   {@see TenantContext}, s'il n'est pas déjà fourni.
 *
 * L'entité racine (Boutique) n'utilise PAS ce trait.
 */
trait BelongsToBoutique
{
    public static function bootBelongsToBoutique(): void
    {
        static::addGlobalScope(new BoutiqueScope);

        static::creating(function ($model): void {
            if ($model->boutique_id === null) {
                $model->boutique_id = app(TenantContext::class)->boutiqueId();
            }
        });
    }

    /**
     * @return BelongsTo<Boutique, $this>
     */
    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }

    /**
     * Requête sans le filtre tenant (usage admin/système à manier avec
     * soin).
     *
     * @return Builder<static>
     */
    public static function withoutBoutiqueScope(): Builder
    {
        return static::query()->withoutGlobalScope(BoutiqueScope::class);
    }
}
