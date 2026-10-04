<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\MoyenPaiement;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ReglementCredit;
use App\Models\Vente;
use App\Services\Fidelite;
use App\Services\SessionCaisseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    /**
     * En tête de l'écran Clients : combien de clients, et ce que tous vous
     * doivent — la liste, paginée, ne permet pas de l'additionner.
     */
    public function resume(): JsonResponse
    {
        $soldes = Client::query()->avecSoldeDu()->get(['id'])
            ->map(fn (Client $c) => max(0, (int) $c->credit_total - (int) $c->reglements_total));

        return response()->json([
            'clients' => $soldes->count(),
            'debiteurs' => $soldes->filter()->count(),
            'encours' => (int) $soldes->sum(),
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
        ]);

        $client->update($data);

        return response()->json($client);
    }

    /** Ce que le client doit, ses achats à crédit et ses règlements. */
    public function credit(Client $client): JsonResponse
    {
        return response()->json([
            'solde_du' => $client->soldeDu(),
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

    /** Le client rembourse tout ou partie de sa dette. */
    public function reglement(Request $request, Client $client): JsonResponse
    {
        $solde = $client->soldeDu();
        $data = $request->validate([
            'montant' => ['required', 'integer', 'min:1', 'max:'.max(1, $solde)],
            'moyen_paiement' => ['required', Rule::in(array_values(array_filter(
                array_map(fn (MoyenPaiement $m) => $m->value, MoyenPaiement::cases()),
                fn (string $m) => $m !== MoyenPaiement::CreditClient->value,
            )))],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['montant.max' => "Le client ne doit que {$solde}."], ['montant' => 'montant']);

        if ($solde <= 0) {
            throw ValidationException::withMessages(['montant' => ['Ce client ne doit rien.']]);
        }

        // La séance du caissier : un remboursement en espèces entre dans son
        // tiroir. Numéro de reçu et dette avant / après, figés maintenant.
        $reglement = DB::transaction(fn () => $client->reglements()->create($data + [
            'user_id' => $request->user()->id,
            'session_caisse_id' => app(SessionCaisseService::class)->courante($request->user())?->id,
            'numero' => (int) ReglementCredit::lockForUpdate()->max('numero') + 1,
            'solde_avant' => $solde,
            'solde_apres' => $solde - (int) $data['montant'],
        ]));

        return response()->json(['solde_du' => $client->soldeDu(), 'recu' => $reglement->load(['caissier', 'client'])->recu()], 201);
    }

    public function destroy(Client $client): JsonResponse
    {
        $client->delete();

        return response()->json(status: 204);
    }
}
