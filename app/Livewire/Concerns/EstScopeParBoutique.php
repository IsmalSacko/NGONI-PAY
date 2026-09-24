<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rejoue SetTenantContext pour les composants Livewire.
 *
 * Livewire ne route ses appels AJAX (`/livewire/update`) qu'à travers le
 * groupe de middleware `web` — jamais les middlewares de la route qui a
 * rendu la page au premier chargement (voir
 * Livewire\Mechanisms\HandleRequests\HandleRequests::boot()). Sans ce
 * trait, le TenantContext reste vide pour toute action (submit, click...)
 * suivant le rendu initial, et BelongsToBoutique ne peut plus renseigner
 * `boutique_id` à la création — `boot()` d'un composant Livewire, lui,
 * s'exécute à CHAQUE requête (premier rendu et actions suivantes), donc
 * c'est ici qu'on repose le contexte.
 */
trait EstScopeParBoutique
{
    public function bootEstScopeParBoutique(): void
    {
        $user = Auth::user();

        if ($user === null || $user->boutique_id === null) {
            return;
        }

        app(TenantContext::class)->setBoutique($user->boutique_id);

        if (App::bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->boutique_id);
        }
    }
}
