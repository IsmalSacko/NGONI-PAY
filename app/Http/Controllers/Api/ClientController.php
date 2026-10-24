<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\MoyenPaiement;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ReglementCredit;
use App\Models\Vente;
use App\Services\Fidelite;
use App\Services\ReglementsCredit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    /**
     * En tête de l'écran Clients : combien de clients, et ce que tous vous
     * doivent — la liste, paginée, ne permet pas de l'additionner.
     */
    public function resume(): JsonResponse
    {
        $soldes = Client::query()->avecSoldeDu()->get(['id'])
            ->map(fn (Client $c) => (int) $c->credit_total - (int) $c->reglements_total);

        return response()->json([
            'clients' => $soldes->count(),
            'debiteurs' => $soldes->filter(fn (int $s) => $s > 0)->count(),
            'encours' => (int) $soldes->filter(fn (int $s) => $s > 0)->sum(),
            // L'argent des clients gardé par la boutique (avoirs).
            'avoirs' => $soldes->filter(fn (int $s) => $s < 0)->count(),
            'avoirs_total' => (int) -$soldes->filter(fn (int $s) => $s < 0)->sum(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        // Achats validés : combien, et pour combien.
        $query = Client::query()
            ->withCount(['ventes as achats' => fn ($q) => $q->valides()])
            ->withSum(['ventes as total_achats' => fn ($q) => $q->valides()], 'total')
            ->avecSoldeDu()
            ->addSelect(Fidelite::colonneAchats());

        if ($request->filled('recherche')) {
            $terme = '%'.$request->string('recherche').'%';
            $query->where(function ($q) use ($terme): void {
                $q->where('nom', 'like', $terme)->orWhere('telephone', 'like', $terme)->orWhere('email', 'like', $terme);
            });
        }

        if ($request->boolean('debiteurs')) {
            // Ceux qui doivent encore : restes dus moins remboursements > 0 (un
            // client qui a tout remboursé n'y est plus). Les plus gros d'abord.
            $dette = '(COALESCE((SELECT SUM(v.reste_du) FROM ventes v WHERE v.client_id = clients.id AND v.statut = ?), 0)'
                .' - COALESCE((SELECT SUM(r.montant) FROM reglements_credit r WHERE r.client_id = clients.id), 0))';
            $query->whereRaw("$dette > 0", [Vente::STATUT_VALIDEE])->reorder()->orderByRaw("$dette DESC", [Vente::STATUT_VALIDEE]);
        }

        $page = $query->orderBy('nom')->paginate(30);
        $page->getCollection()->each(function (Client $c): void {
            $c->setAttribute('solde_du', max(0, (int) $c->credit_total - (int) $c->reglements_total));
            $c->setAttribute('avoir', max(0, (int) $c->reglements_total - (int) $c->credit_total));
            $c->makeHidden(['credit_total', 'reglements_total']);
        });

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Revendeur : ses achats passent d'eux-mêmes au prix de gros.
            'revendeur' => ['nullable', 'boolean'],
            'plafond_credit' => ['nullable', 'integer', 'min:0'],
            // Facture « distributeur » : code client, NIU, adresse.
            'code_client' => ['nullable', 'string', 'max:30'],
            'niu' => ['nullable', 'string', 'max:40'],
            'adresse' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(Client::create($data), 201);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Revendeur : ses achats passent d'eux-mêmes au prix de gros.
            'revendeur' => ['nullable', 'boolean'],
            'plafond_credit' => ['nullable', 'integer', 'min:0'],
            // Facture « distributeur » : code client, NIU, adresse.
            'code_client' => ['nullable', 'string', 'max:30'],
            'niu' => ['nullable', 'string', 'max:40'],
            'adresse' => ['nullable', 'string', 'max:255'],
        ]);

        $client->update($data);

        return response()->json($client);
    }

    /** Ce que le client doit, ses achats à crédit et ses règlements. */
    public function credit(Client $client): JsonResponse
    {
        return response()->json([
            'solde_du' => $client->soldeDu(),
            'avoir' => $client->avoir(),
            // Les ventes qui ont laissé une dette : payé sur le moment, et le reste.
            'achats' => $client->ventes()->valides()->where('reste_du', '>', 0)->latest()
                ->get(['id', 'numero', 'total', 'montant_paye', 'reste_du', 'created_at', 'boutique_id'])
                ->map(fn (Vente $v) => ['id' => $v->id, 'numero_facture' => $v->numero_facture, 'total' => $v->total,
                    'paye' => (int) $v->montant_paye, 'reste' => (int) $v->reste_du, 'date' => $v->created_at]),
            // Chaque remboursement porte son reçu (réimprimable).
            'reglements' => $client->reglements()->with(['caissier:id,name', 'client'])->orderByDesc('numero')->latest()->get()
                ->map(fn (ReglementCredit $r) => $r->recu()),
        ]);
    }

    /**
     * Le client verse de l'argent : il rembourse sa dette, et ce qui dépasse
     * devient son avoir (monnaie gardée, avance), déduit de ses prochains
     * achats à crédit.
     */
    public function reglement(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'montant' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'moyen_paiement' => ['required', Rule::in(array_values(array_filter(
                array_map(fn (MoyenPaiement $m) => $m->value, MoyenPaiement::cases()),
                fn (string $m) => $m !== MoyenPaiement::CreditClient->value,
            )))],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['montant' => 'montant']);

        $reglement = app(ReglementsCredit::class)->enregistrer($client, $request->user(), (int) $data['montant'], $data['moyen_paiement'], $data['note'] ?? null);

        return response()->json(['solde_du' => $client->soldeDu(), 'avoir' => $client->avoir(), 'recu' => $reglement->load(['caissier', 'client'])->recu()], 201);
    }

    public function destroy(Client $client): JsonResponse
    {
        $client->delete();

        return response()->json(status: 204);
    }
}
