<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\MoyenPaiement;
use App\Http\Controllers\Controller;
use App\Models\Paiement;
use App\Services\Paiement\PaiementService;
use App\Services\Paiement\PasserelleIndisponible;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Paiements en ligne (couche optionnelle au-dessus de POST /ventes, qui reste
 * inchangé). La tablette ouvre un paiement, affiche le lien/QR au client puis
 * relit son état jusqu'à obtenir la vente créée par le serveur.
 */
class PaiementController extends Controller
{
    public function __construct(private readonly PaiementService $paiements) {}

    /**
     * Moyens de paiement réellement branchés sur un fournisseur pour cette
     * boutique. Liste vide (fournisseurs non configurés, boutique hors FCFA) :
     * la caisse n'affiche pas « Payer en ligne » et reste 100 % déclarative.
     */
    public function fournisseurs(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->paiements->disponibles($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference_locale' => ['nullable', 'uuid'],
            'client_id' => ['nullable', 'uuid', Rule::exists('clients', 'id')->where('boutique_id', $request->user()->boutique_id)],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'uuid'],
            'lignes.*.quantite' => ['required', 'integer', 'min:1'],
            'remise' => ['nullable', 'integer', 'min:0'],
            'moyen_paiement' => ['required', Rule::enum(MoyenPaiement::class)],
        ]);

        try {
            $paiement = $this->paiements->initier($data, $request->user());
        } catch (PasserelleIndisponible $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($this->presenter($paiement), 201);
    }

    /**
     * Relit le paiement (chez le fournisseur s'il n'est pas encore confirmé) et
     * renvoie la vente dès qu'elle existe.
     */
    public function show(Request $request, Paiement $paiement): JsonResponse
    {
        return response()->json($this->presenter($this->paiements->synchroniser($paiement)));
    }

    public function annuler(Request $request, Paiement $paiement): JsonResponse
    {
        return response()->json($this->presenter($this->paiements->annuler($paiement)));
    }

    /**
     * @return array<string, mixed>
     */
    private function presenter(Paiement $paiement): array
    {
        $paiement->loadMissing(['vente.lignes', 'vente.client', 'vente.caissier']);

        return [
            'id' => $paiement->id,
            'statut' => $paiement->statut->value,
            'statut_libelle' => $paiement->statut->label(),
            'fournisseur' => $paiement->fournisseur->value,
            'moyen_paiement' => $paiement->moyen_paiement->value,
            'montant' => $paiement->montant,
            'devise_fournisseur' => $paiement->devise_fournisseur,
            'montant_fournisseur' => $paiement->montant_fournisseur,
            'url_paiement' => $paiement->url_paiement,
            'erreur' => $paiement->erreur,
            'vente' => $paiement->vente,
        ];
    }
}
