<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Concerns\BelongsToBoutique;

/**
 * Contexte de tenant courant, résolu par requête (singleton — voir
 * AppServiceProvider::register()).
 *
 * Porte l'identifiant de la boutique active. Toutes les entités métier
 * utilisant le trait {@see BelongsToBoutique} sont automatiquement filtrées
 * et renseignées à partir de cette valeur.
 */
class TenantContext
{
    private ?string $boutiqueId = null;

    public function setBoutique(?string $boutiqueId): void
    {
        $this->boutiqueId = $boutiqueId;
    }

    /**
     * Identifiant de la boutique active, ou null hors contexte tenant
     * (inscription, jobs système, console...).
     */
    public function boutiqueId(): ?string
    {
        return $this->boutiqueId;
    }

    public function hasBoutique(): bool
    {
        return $this->boutiqueId !== null;
    }

    /**
     * Réinitialise le contexte (utile en tests et files d'attente).
     */
    public function forget(): void
    {
        $this->boutiqueId = null;
    }
}
