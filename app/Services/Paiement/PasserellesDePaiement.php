<?php

declare(strict_types=1);

namespace App\Services\Paiement;

use App\Enums\FournisseurPaiement;
use App\Enums\MoyenPaiement;

/**
 * Annuaire des passerelles : quel fournisseur sert quel moyen de paiement
 * (config/paiements.php), et lesquels sont réellement utilisables — c'est-à-dire
 * dont les clés sont renseignées sur ce serveur.
 */
class PasserellesDePaiement
{
    public function __construct(
        private readonly PayPalPasserelle $paypal,
        private readonly PayDunyaPasserelle $paydunya,
    ) {}

    public function pour(FournisseurPaiement $fournisseur): PasserelleDePaiement
    {
        return match ($fournisseur) {
            FournisseurPaiement::PayPal => $this->paypal,
            FournisseurPaiement::PayDunya => $this->paydunya,
        };
    }

    /**
     * La passerelle qui encaisserait ce moyen de paiement en ligne, ou null si
     * le moyen reste déclaratif (ou si le fournisseur n'est pas configuré).
     */
    public function pourMoyen(MoyenPaiement $moyen): ?PasserelleDePaiement
    {
        $fournisseur = FournisseurPaiement::tryFrom((string) config("paiements.moyens.{$moyen->value}"));

        if ($fournisseur === null) {
            return null;
        }

        $passerelle = $this->pour($fournisseur);

        return $passerelle->estConfiguree() ? $passerelle : null;
    }

    /**
     * @return list<array{moyen_paiement: string, fournisseur: string, mode: string}>
     */
    public function disponibles(): array
    {
        $liste = [];

        foreach (MoyenPaiement::cases() as $moyen) {
            $passerelle = $this->pourMoyen($moyen);

            if ($passerelle !== null) {
                $liste[] = [
                    'moyen_paiement' => $moyen->value,
                    'fournisseur' => $passerelle->fournisseur()->value,
                    'mode' => $passerelle->mode(),
                ];
            }
        }

        return $liste;
    }
}
