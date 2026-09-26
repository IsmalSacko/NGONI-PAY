<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Support\Tenancy\BoutiqueActive;
use Illuminate\Support\Facades\Auth;

/**
 * Rejoue le contexte tenant pour les composants Livewire.
 *
 * Livewire ne route ses appels AJAX (`/livewire/update`) qu'à travers le
 * groupe de middleware `web` — jamais les middlewares de la route qui a
 * rendu la page au premier chargement (voir
 * Livewire\Mechanisms\HandleRequests\HandleRequests::boot()). Sans ce
 * trait, le TenantContext reste vide pour toute action (submit, click...)
 * suivant le rendu initial, et BelongsToBoutique ne peut plus renseigner
 * `boutique_id` à la création — `boot()` d'un composant Livewire, lui,
 * s'exécute à CHAQUE requête (premier rendu et actions suivantes), donc
 * c'est ici qu'on repose le contexte : la boutique gardée en session si le
 * compte y a toujours un rôle, sinon sa boutique par défaut.
 */
trait EstScopeParBoutique
{
    public function bootEstScopeParBoutique(): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        app(BoutiqueActive::class)->poser($user, session(BoutiqueActive::CLE_SESSION));
    }

    /** Boutique active de la requête en cours. */
    protected function boutiqueActiveId(): ?string
    {
        return app(\App\Support\Tenancy\TenantContext::class)->boutiqueId();
    }

    /**
     * Sans abonnement en cours, le back-office reste consultable mais n'agit
     * plus (comme l'API). Rend `false` et prévient l'utilisateur.
     */
    protected function abonnementActif(): bool
    {
        $boutique = \App\Models\Boutique::find($this->boutiqueActiveId());

        if (app(\App\Services\AbonnementService::class)->boutiqueActive($boutique)) {
            return true;
        }

        session()->flash('abonnement_expire', 'Votre essai ou abonnement est terminé : les données restent consultables, '
            .'mais aucune modification n’est possible. Abonnez-vous depuis l’application.');
        $this->dispatch('abonnement-expire');

        return false;
    }
}
