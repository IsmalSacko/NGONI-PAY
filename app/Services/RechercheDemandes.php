<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutDemande;
use App\Models\DemandeAbonnement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Demandes d'abonnement de la console, web comme application : un onglet par
 * statut, une recherche (boutique, téléphone, nom, e-mail), et l'ordre de
 * travail — à traiter, la plus ancienne d'abord ; le reste, la plus récente.
 */
class RechercheDemandes
{
    /** Onglet : en_attente | approuvee | refusee | annulee | toutes. */
    public function requete(string $onglet, string $recherche): Builder
    {
        $statut = StatutDemande::tryFrom($onglet);

        return DemandeAbonnement::with(['proprietaire.abonnement', 'proprietaire.parrain:id,name', 'parrainRecompense:id,name', 'boutique', 'demandeur'])
            ->when($statut, fn ($q) => $q->where('statut', $statut->value))
            ->tap(fn (Builder $q) => $this->chercher($q, $recherche))
            ->when($statut === StatutDemande::EnAttente, fn ($q) => $q->oldest('id'), fn ($q) => $q
                ->orderByRaw('CASE WHEN statut = ? THEN 0 ELSE 1 END', [StatutDemande::EnAttente->value])
                ->latest('id'));
    }

    /**
     * Le nombre de chaque onglet, recherche comprise.
     *
     * @return Collection<string, int>
     */
    public function comptes(string $recherche): Collection
    {
        return DemandeAbonnement::query()->tap(fn (Builder $q) => $this->chercher($q, $recherche))->toBase()
            ->selectRaw('statut, count(*) as n')->groupBy('statut')->pluck('n', 'statut')->map(fn ($n) => (int) $n);
    }

    /**
     * Dans la boutique, le propriétaire, le demandeur et le numéro de contact.
     * Un numéro se cherche par ses chiffres : « 76 00 00 00 », « +223 76… » et
     * « 76000000 » se retrouvent.
     */
    private function chercher(Builder $q, string $recherche): void
    {
        $terme = trim($recherche);
        if ($terme === '') {
            return;
        }
        $texte = '%'.$terme.'%';
        $chiffres = (string) preg_replace('/\D/', '', $terme);
        $personne = function (Builder $u) use ($texte, $chiffres): void {
            $u->where('name', 'like', $texte)->orWhere('email', 'like', $texte);
            if (strlen($chiffres) >= 4) {
                $u->orWhere('phone', 'like', '%'.$chiffres.'%');
            }
        };
        $q->where(function (Builder $w) use ($texte, $chiffres, $personne): void {
            $w->whereHas('boutique', fn (Builder $b) => $b->where('nom', 'like', $texte))
                ->orWhereHas('proprietaire', $personne)
                ->orWhereHas('demandeur', $personne);
            if (strlen($chiffres) >= 4) {
                $w->orWhere('telephone_contact', 'like', '%'.$chiffres.'%');
            }
        });
    }
}
