<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\Vente;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Numéro de facture d'une boutique : « PRÉFIXE-2026-0001-SUFFIXE », réglable
 * dans Ma boutique (préfixe, suffixe, année ou non, prochain numéro).
 *
 * Un compteur par série (préfixe + suffixe + année) : un numéro déjà donné à
 * un client ne resert jamais — on ne peut pas faire reculer le compteur d'une
 * série ; pour repartir à 1, on change de préfixe (nouvelle série). Sans
 * l'année, la série ne repart pas au 1er janvier.
 *
 * En mode rodage, les numéros sont préfixés « ESSAI- » et ont leurs propres
 * compteurs, remis à zéro au passage en mode réel.
 */
class NumerotationFactures
{
    /** Numéro de la prochaine vente, consommé (le compteur avance). */
    public function prendre(string $boutiqueId, mixed $creeLe = null): string
    {
        $boutique = Boutique::withoutGlobalScopes()->whereKey($boutiqueId)->firstOrFail();
        $annee = (int) ($creeLe === null ? now() : Carbon::parse($creeLe))->format('Y');
        $compteurs = $boutique->compteurs_facture ?? [];
        $serie = $this->serie($boutique, $annee);
        $numero = (int) ($compteurs[$serie] ?? 0) + 1;
        $compteurs[$serie] = $numero;
        Boutique::withoutGlobalScopes()->whereKey($boutiqueId)->update(['compteurs_facture' => json_encode($compteurs)]);

        return $this->formater($boutique, $numero, $annee);
    }

    /** Le numéro que portera la prochaine vente, sans le consommer. */
    public function apercu(Boutique $boutique): string
    {
        $annee = (int) now()->format('Y');

        return $this->formater($boutique, $this->dernier($boutique, $annee) + 1, $annee);
    }

    /**
     * Le prochain numéro de la série en cours. Plus bas qu'un numéro déjà
     * donné : refusé (sauf en rodage, où rien n'a été remis pour de vrai).
     */
    public function fixerProchain(Boutique $boutique, int $prochain): void
    {
        $annee = (int) now()->format('Y');
        $dernier = $this->dernier($boutique, $annee);
        if ($prochain <= $dernier && ! $boutique->mode_rodage) {
            throw ValidationException::withMessages(['facture_prochain_numero' => [
                "Les numéros jusqu'à {$dernier} ont déjà été donnés dans cette série : choisissez un numéro plus grand, "
                .'ou changez de préfixe pour repartir à 1.',
            ]]);
        }
        $compteurs = $boutique->compteurs_facture ?? [];
        $compteurs[$this->serie($boutique, $annee)] = $prochain - 1;
        $boutique->forceFill(['compteurs_facture' => $compteurs])->save();
    }

    public function prefixe(Boutique $boutique): string
    {
        return $boutique->facture_prefixe ?: Vente::initiales((string) $boutique->nom);
    }

    public function formater(Boutique $boutique, int $numero, int $annee): string
    {
        return ($boutique->mode_rodage ? 'ESSAI-' : '')
            .$this->prefixe($boutique)
            .($boutique->facture_annee ? '-'.$annee : '')
            .'-'.str_pad((string) $numero, 4, '0', STR_PAD_LEFT)
            .($boutique->facture_suffixe ? '-'.$boutique->facture_suffixe : '');
    }

    private function dernier(Boutique $boutique, int $annee): int
    {
        return (int) (($boutique->compteurs_facture ?? [])[$this->serie($boutique, $annee)] ?? 0);
    }

    /** « *||2026 » : préfixe automatique, sans suffixe, année 2026. */
    private function serie(Boutique $boutique, int $annee): string
    {
        return ($boutique->mode_rodage ? 'ESSAI|' : '')
            .($boutique->facture_prefixe ?: '*').'|'.($boutique->facture_suffixe ?? '').'|'.($boutique->facture_annee ? $annee : '-');
    }
}
