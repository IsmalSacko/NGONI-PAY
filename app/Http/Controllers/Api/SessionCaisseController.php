<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SessionCaisse;
use App\Services\SessionCaisseService;
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

        return response()->json($session);
    }

    public function ouvrir(Request $request): JsonResponse
    {
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
