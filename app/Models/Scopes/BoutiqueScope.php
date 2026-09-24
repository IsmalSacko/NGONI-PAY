<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global Scope multi-tenant : restreint toutes les requêtes à la boutique
 * active du {@see TenantContext}. No-op lorsqu'aucun tenant n'est défini
 * (inscription, seeders, jobs système), afin de ne pas masquer de données
 * involontairement lors des opérations hors contexte.
 *
 * @implements Scope<Model>
 */
class BoutiqueScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $boutiqueId = app(TenantContext::class)->boutiqueId();

        if ($boutiqueId !== null) {
            $builder->where($model->getTable().'.boutique_id', $boutiqueId);
        }
    }
}
