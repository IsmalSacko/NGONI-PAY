<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\MoyenPaiement;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ForfaitPressing;
use App\Models\FourniturePressing;
use App\Services\GestionPressing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Pressing, offre Pro : dépenses, fournitures, forfaits clients, relevé mensuel. */
class GestionPressingController extends Controller
{
    public function __construct(private readonly GestionPressing $gestion) {}

    public function fournitures(): JsonResponse
    {
        $this->gestion->exiger();

        return response()->json(['data' => FourniturePressing::orderBy('nom')->get()->map(fn ($f) => $this->fourniture($f))->values()]);
    }

    public function creerFourniture(Request $request): JsonResponse
    {
        $this->gestion->exiger();
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:80'],
            'unite' => ['nullable', 'string', 'max:20'],
            'quantite' => ['nullable', 'numeric', 'min:0'],
            'seuil' => ['nullable', 'numeric', 'min:0'],
        ]);
        $f = FourniturePressing::create(['nom' => trim($data['nom']), 'unite' => $data['unite'] ?? 'unité', 'quantite' => $data['quantite'] ?? 0, 'seuil' => $data['seuil'] ?? null]);

        return response()->json(['data' => $this->fourniture($f)], 201);
    }

    public function modifierFourniture(Request $request, FourniturePressing $fourniture): JsonResponse
    {
        $this->gestion->exiger();
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:80'],
            'unite' => ['nullable', 'string', 'max:20'],
            'seuil' => ['nullable', 'numeric', 'min:0'],
        ]);
        $fourniture->update(['nom' => trim($data['nom']), 'unite' => $data['unite'] ?? $fourniture->unite, 'seuil' => $data['seuil'] ?? null]);

        return response()->json(['data' => $this->fourniture($fourniture)]);
    }

    public function supprimerFourniture(FourniturePressing $fourniture): JsonResponse
    {
        $this->gestion->exiger();
        $fourniture->delete();

        return response()->json(['ok' => true]);
    }

    /** Entrée (quantité positive, achat payé si montant) ou sortie (négative). */
    public function mouvement(Request $request, FourniturePressing $fourniture): JsonResponse
    {
        $this->gestion->exiger();
        $data = $request->validate([
            'quantite' => ['required', 'numeric', 'not_in:0'],
            'montant' => ['nullable', 'integer', 'min:0'],
            'moyen_paiement' => ['nullable', Rule::enum(MoyenPaiement::class)],
        ]);
        $f = $this->gestion->mouvement($fourniture, (float) $data['quantite'], $data['montant'] ?? null, $data['moyen_paiement'] ?? null, $request->user());

        return response()->json(['data' => $this->fourniture($f)]);
    }

    /** Forfaits : tous, ou ceux d'un client (?client_id=), les actifs d'abord. */
    public function forfaits(Request $request): JsonResponse
    {
        $this->gestion->exiger();
        $forfaits = ForfaitPressing::with('client')
            ->when($request->query('client_id'), fn ($q, string $id) => $q->where('client_id', $id))
            ->orderByDesc('fin')->limit(200)->get()
            ->sortByDesc(fn (ForfaitPressing $f) => $f->actif())->values();

        return response()->json(['data' => $forfaits->map(fn ($f) => $this->forfait($f))->values()]);
    }

    public function vendreForfait(Request $request): JsonResponse
    {
        $this->gestion->exiger();
        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'libelle' => ['required', 'string', 'max:80'],
            'pieces' => ['required', 'integer', 'min:1', 'max:10000'],
            'prix' => ['required', 'integer', 'min:0'],
            'fin' => ['required', 'date', 'after_or_equal:today'],
            'moyen_paiement' => ['required', Rule::enum(MoyenPaiement::class)],
        ]);

        return response()->json(['data' => $this->forfait($this->gestion->vendreForfait($data, $request->user())->load('client'))], 201);
    }

    /** Relevé mensuel d'un client (comptes entreprises). ?mois=AAAA-MM */
    public function releve(Request $request, Client $client): JsonResponse
    {
        $this->gestion->exiger();
        $mois = Carbon::parse($request->query('mois', now()->format('Y-m')).'-01');

        return response()->json(['data' => $this->gestion->releve($client, $mois)]);
    }

    /** @return array<string, mixed> */
    private function fourniture(FourniturePressing $f): array
    {
        return ['id' => $f->id, 'nom' => $f->nom, 'unite' => $f->unite, 'quantite' => $f->quantite, 'seuil' => $f->seuil, 'a_racheter' => $f->aRacheter()];
    }

    /** @return array<string, mixed> */
    private function forfait(ForfaitPressing $f): array
    {
        return [
            'id' => $f->id, 'libelle' => $f->libelle, 'pieces' => $f->pieces, 'restantes' => $f->restantes(), 'prix' => $f->prix,
            'debut' => $f->debut->toDateString(), 'fin' => $f->fin->toDateString(), 'actif' => $f->actif(),
            'client' => $f->client === null ? null : ['id' => $f->client->id, 'nom' => $f->client->nom, 'telephone' => $f->client->telephone],
        ];
    }
}
