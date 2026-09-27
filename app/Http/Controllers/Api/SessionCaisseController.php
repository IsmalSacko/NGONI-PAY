<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\Plan;
use App\Models\SessionCaisse;
use App\Services\AbonnementService;
use App\Services\SessionCaisseService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionCaisseController extends Controller
{
    public function __construct(private readonly SessionCaisseService $sessions) {}

    public function index(): JsonResponse
    {
        $sessions = SessionCaisse::with('caissier')->latest('ouverte_le')->paginate(30);

        return response()->json($sessions);
    }

    public function courante(Request $request): JsonResponse
    {
        $session = $this->sessions->courante($request->user());

        // L'application pré-remplit le comptage de fin de séance avec ce montant.
        $session?->setAttribute('total_especes', $this->sessions->totalEspeces($session))
            ->setAttribute('fond_attendu', $this->sessions->fondAttendu($session));

        return response()->json($session);
    }

    /** Fond proposé à l'ouverture : celui laissé à la dernière fermeture. */
    public function suggestionOuverture(): JsonResponse
    {
        $derniere = $this->sessions->derniereFermeture();

        return response()->json([
            'fond_suggere' => $derniere?->fond_final,
            'fermee_le' => $derniere?->fermee_le?->toIso8601String(),
        ]);
    }

    public function ouvrir(Request $request): JsonResponse
    {
        // Réservé au Pro. Sans séance, la caisse encaisse quand même : la vente
        // n'est simplement rattachée à aucune séance. Une séance déjà ouverte
        // (pendant l'essai) peut toujours être clôturée.
        $boutique = Boutique::find(app(TenantContext::class)->boutiqueId());
        if (! app(AbonnementService::class)->permet($boutique, Plan::SEANCES_CAISSE)) {
            return response()->json([
                'message' => 'Les séances de caisse et le suivi des écarts sont inclus dans le plan Pro.',
                'code' => 'FONCTION_PRO',
            ], 403);
        }

        $data = $request->validate([
            'fond_initial' => ['required', 'integer', 'min:0'],
        ]);

        $session = $this->sessions->ouvrir($request->user(), $data['fond_initial']);

        return response()->json($session, 201);
    }

    public function fermer(Request $request, SessionCaisse $session): JsonResponse
    {
        // N'importe quel caissier avec la permission pourrait sinon clôturer
        // la séance d'un collègue : seule la propriétaire de la séance ou un
        // rôle d'encadrement (gérant/admin, en fin de journée) le peut.
        abort_unless(
            $session->user_id === $request->user()->id || $request->user()->hasRole(['admin', 'gerant']),
            403,
        );

        $data = $request->validate([
            'fond_final' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $session = $this->sessions->fermer($session, $data['fond_final'], $data['notes'] ?? null);

        return response()->json($session);
    }
}
