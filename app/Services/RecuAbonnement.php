<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MoyenPaiement;
use App\Models\DemandeAbonnement;
use App\Models\Plan;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Reçu d'un abonnement payé : la preuve que le commerçant garde ou transmet
 * (offre, période couverte, montant, moyen et référence du paiement).
 */
class RecuAbonnement
{
    /** Numéro lisible et stable, tiré de la demande : NGC-2026-00042. */
    public static function numero(DemandeAbonnement $demande): string
    {
        return 'NGC-'.($demande->decide_le ?? now())->format('Y').'-'.str_pad((string) $demande->id, 5, '0', STR_PAD_LEFT);
    }

    public function pdf(DemandeAbonnement $demande): string
    {
        return Pdf::loadView('abonnement.recu', [
            'd' => $demande,
            'plan' => Plan::parCode($demande->plan)?->nom ?? ucfirst($demande->plan),
            'moyen' => $this->moyen($demande),
            'reference' => $demande->jeko_transaction_id ?? $demande->jeko_paiement_id ?? $demande->fedapay_transaction_id ?? $demande->pawapay_deposit_id,
            'client' => $demande->boutique?->nom ?? $demande->proprietaire?->name,
        ])->setPaper('a5')->output();
    }

    public function nomFichier(DemandeAbonnement $demande): string
    {
        return 'recu-'.strtolower((string) $demande->recu_numero).'.pdf';
    }

    private function moyen(DemandeAbonnement $demande): string
    {
        // Paiement en ligne d'un prestataire sans libellé connu ici : Mobile Money.
        return $demande->libellePaiementEnLigne()
            ?? MoyenPaiement::tryFrom((string) $demande->moyen)?->label()
            ?? (preg_match('/^(jeko|fedapay|pawapay)_/', (string) $demande->moyen) ? 'Mobile Money' : 'Paiement');
    }
}
