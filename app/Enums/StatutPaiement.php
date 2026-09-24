<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cycle de vie d'un paiement en ligne.
 *
 * `Confirme` signifie que le fournisseur a encaissé l'argent ; la vente est
 * créée juste après (`Paiement::vente_id`). Un paiement confirmé sans vente
 * reste visible avec son `erreur` pour un traitement manuel.
 */
enum StatutPaiement: string
{
    case EnAttente = 'en_attente';
    case Confirme = 'confirme';
    case Echoue = 'echoue';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Confirme => 'Confirmé',
            self::Echoue => 'Échoué',
            self::Annule => 'Annulé',
        };
    }

    /**
     * Le fournisseur reste la source de vérité tant que le paiement n'est
     * pas confirmé : un paiement « annulé » côté caisse peut avoir été payé
     * par le client une seconde plus tard. On ne veut pas perdre cet argent.
     */
    public function aVerifierChezLeFournisseur(): bool
    {
        return $this === self::EnAttente || $this === self::Annule;
    }
}
