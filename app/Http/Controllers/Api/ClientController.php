<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Enums\MoyenPaiement;
use App\Models\Client;
use App\Models\ReglementCredit;
use App\Models\Vente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Achats validés : combien, et pour combien.
        $query = Client::query()
            ->withCount(['ventes as achats' => fn ($q) => $q->valides()])
            ->withSum(['ventes as total_achats' => fn ($q) => $q->valides()], 'total')
            ->avecSoldeDu();

        if ($request->filled('recherche')) {
            $terme = '%'.$request->string('recherche').'%';
            $query->where(function ($q) use ($terme): void {
                $q->where('nom', 'like', $terme)->orWhere('telephone', 'like', $terme)->orWhere('email', 'like', $terme);
            });
        }

        if ($request->boolean('debiteurs')) {
            $query->whereHas('ventes', fn ($q) => $q->valides()->where('moyen_paiement', 'credit_client'));
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
        ]);

        $client->update($data);

        return response()->json($client);
    }

    /** Ce que le client doit, ses achats à crédit et ses règlements. */
    public function credit(Client $client): JsonResponse
    {
        return response()->json([
            'solde_du' => $client->soldeDu(),
            'achats' => $client->ventes()->valides()->where('moyen_paiement', 'credit_client')->latest()
                ->get(['id', 'numero', 'total', 'created_at', 'boutique_id'])
                ->map(fn (Vente $v) => ['id' => $v->id, 'numero_facture' => $v->numero_facture, 'total' => $v->total, 'date' => $v->created_at]),
            'reglements' => $client->reglements()->with('caissier:id,name')->latest()->get()
                ->map(fn (ReglementCredit $r) => ['id' => $r->id, 'montant' => $r->montant, 'moyen_paiement' => $r->moyen_paiement,
                    'note' => $r->note, 'date' => $r->created_at, 'par' => $r->caissier?->name]),
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

        $client->reglements()->create($data + ['user_id' => $request->user()->id]);

        return response()->json(['solde_du' => $client->soldeDu()], 201);
    }

    public function destroy(Client $client): JsonResponse
    {
        $client->delete();

        return response()->json(status: 204);
    }
}
