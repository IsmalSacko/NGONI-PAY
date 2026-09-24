<?php

declare(strict_types=1);

namespace App\Services\Paiement;

/**
 * Résultat de l'ouverture d'un paiement chez le fournisseur : la référence à
 * retenir (jeton PayDunya, identifiant de commande PayPal) et l'adresse de la
 * page de paiement à faire ouvrir par le client.
 */
final readonly class SessionPaiement
{
    public function __construct(public string $reference, public string $url) {}
}
