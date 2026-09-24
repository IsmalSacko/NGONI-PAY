<?php

declare(strict_types=1);

namespace App\Services\Paiement;

use App\Enums\FournisseurPaiement;
use App\Models\Paiement;

/**
 * Une passerelle de paiement en ligne (PayPal, PayDunya...).
 *
 * e-caisse ne manipule jamais de données de carte : le client paie sur la
 * page hébergée du fournisseur, e-caisse n'échange que des jetons et des
 * statuts. Le fournisseur reste la source de vérité — un statut reçu par
 * webhook ou par redirection n'est jamais cru sur parole, il déclenche une
 * relecture via {@see self::consulter()}.
 */
interface PasserelleDePaiement
{
    public function fournisseur(): FournisseurPaiement;

    /** Les clés API sont renseignées dans l'environnement du serveur. */
    public function estConfiguree(): bool;

    /** `test` (bac à sable) ou `live` (argent réel). */
    public function mode(): string;

    /**
     * Ce que le fournisseur facturera pour un total exprimé en FCFA.
     *
     * @return array{devise: string, montant: string}
     */
    public function montantFacture(int $montantFcfa): array;

    /**
     * Ouvre le paiement chez le fournisseur.
     *
     * @throws PasserelleIndisponible
     */
    public function creer(Paiement $paiement, string $urlRetour, string $urlAnnulation, string $urlNotification): SessionPaiement;

    /**
     * Relit l'état réel du paiement chez le fournisseur (et, si le
     * fournisseur l'exige — PayPal —, finalise l'encaissement).
     *
     * @throws PasserelleIndisponible
     */
    public function consulter(Paiement $paiement): EtatPaiement;
}
