<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achat;
use App\Models\Fournisseur;
use App\Services\AchatService;
use App\Support\Money\Montant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Fournisseurs, réceptions de marchandise et paiements (gérant, admin). */
class AchatController extends Controller
{
    private const MOYENS = ['especes', 'orange_money', 'moov_money', 'wave', 'carte', 'virement'];

    public function __construct(private readonly AchatService $achats) {}

    public function fournisseurs(): JsonResponse
    {
        return response()->json(['data' => Fournisseur::avecSoldeDu()->orderBy('nom')->get()->map(fn (Fournisseur $f) => [
            'id' => $f->id, 'nom' => $f->nom, 'telephone' => $f->telephone, 'notes' => $f->notes,
            'solde_du' => max(0, (int) $f->achats_total - (int) $f->paiements_total),
        ])]);
    }

    public function creerFournisseur(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(Fournisseur::create($data), 201);
    }

    public function modifierFournisseur(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $fournisseur->update($data);

        return response()->json($fournisseur->fresh());
    }

    /**
     * Retiré de la liste ; ses achats passés restent dans l'historique. Tant que
     * la boutique lui doit de l'argent, il reste : la dette disparaîtrait avec lui.
     */
    public function supprimerFournisseur(Fournisseur $fournisseur): JsonResponse
    {
        if (($du = $fournisseur->soldeDu()) > 0) {
            throw ValidationException::withMessages([
                'fournisseur' => ['Vous lui devez encore '.Montant::format($du).' : réglez-le avant de le retirer.'],
            ]);
        }
        $fournisseur->delete();

        return response()->json(['message' => 'Fournisseur retiré.']);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => Achat::with(['lignes', 'fournisseur:id,nom'])->latest()->limit(100)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $boutique = app(TenantContext::class)->boutiqueId();
        $data = $request->validate([
            'fournisseur_id' => ['nullable', 'uuid', Rule::exists('fournisseurs', 'id')->where('boutique_id', $boutique)->whereNull('deleted_at')],
            'reference' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'uuid', Rule::exists('produits', 'id')->where('boutique_id', $boutique)],
            'lignes.*.quantite' => ['required', 'integer', 'min:1'],
            'lignes.*.prix_achat' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'montant_paye' => ['nullable', 'integer', 'min:0'],
            'moyen_paiement' => ['nullable', Rule::in(self::MOYENS)],
        ]);

        $achat = $this->achats->receptionner($request->user(), $data['lignes'], $data['fournisseur_id'] ?? null,
            $data['reference'] ?? null, (int) ($data['montant_paye'] ?? 0), $data['moyen_paiement'] ?? 'especes', $data['note'] ?? null);

        return response()->json($achat, 201);
    }

    public function payer(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        $data = $request->validate([
            'montant' => ['required', 'integer', 'min:1'],
            'moyen_paiement' => ['required', Rule::in(self::MOYENS)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['solde_du' => $this->achats->payer($fournisseur, $request->user(), $data['montant'], $data['moyen_paiement'], $data['note'] ?? null)], 201);
    }
}
