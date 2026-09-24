<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StatutPaiement;
use App\Models\Paiement;
use App\Services\Paiement\PaiementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Page où le fournisseur renvoie le CLIENT après son paiement (téléphone du
 * client, pas la tablette). Publique, sans donnée sensible : elle ne dit que
 * « reçu / en cours / échoué ». Elle profite du passage pour resynchroniser le
 * paiement — notamment la capture PayPal — sans attendre la tablette.
 */
class PaiementRetourController extends Controller
{
    public function __invoke(Request $request, string $paiement, PaiementService $service): View
    {
        $modele = Paiement::withoutBoutiqueScope()->with('boutique')->findOrFail($paiement);

        if (! $request->boolean('annule')) {
            try {
                $modele = $service->synchroniser($modele);
            } catch (Throwable $e) {
                Log::warning('Page de retour de paiement : relecture impossible', ['paiement' => $modele->id, 'erreur' => $e->getMessage()]);
            }
        }

        return view('paiements.retour', [
            'boutique' => $modele->boutique->nom,
            'statut' => $request->boolean('annule') && $modele->statut === StatutPaiement::EnAttente ? StatutPaiement::Annule : $modele->statut,
            'actualiser' => $modele->statut === StatutPaiement::EnAttente && ! $request->boolean('annule') && $modele->created_at->gt(now()->subMinutes(15)),
        ]);
    }
}
