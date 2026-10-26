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
use App\Services\ReleveCompte;
use App\Support\Money\Montant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        // Restes dus moins remboursements : positif, il doit ; négatif, la
        // boutique lui garde de l'argent (avoir).
        $dette = '(COALESCE((SELECT SUM(v.reste_du) FROM ventes v WHERE v.client_id = clients.id AND v.statut = ?), 0)'
            .' - COALESCE((SELECT SUM(r.montant) FROM reglements_credit r WHERE r.client_id = clients.id), 0))';
        if ($request->boolean('debiteurs')) {
            // Ceux qui doivent encore (un client qui a tout remboursé n'y est
            // plus). Les plus gros d'abord.
            $query->whereRaw("$dette > 0", [Vente::STATUT_VALIDEE])->reorder()->orderByRaw("$dette DESC", [Vente::STATUT_VALIDEE]);
        } elseif ($request->boolean('avoirs')) {
            // Ceux à qui la boutique garde de l'argent, les plus gros d'abord.
            $query->whereRaw("$dette < 0", [Vente::STATUT_VALIDEE])->reorder()->orderByRaw("$dette ASC", [Vente::STATUT_VALIDEE]);
        }

        // L'application ne lit qu'une page : toute la liste d'un coup (500), sinon
        // un commerçant de 40 clients n'en voyait que 30 et ses comptes ne tombaient
        // jamais juste avec le total « Vos clients vous doivent ».
        $page = $query->orderBy('nom')->paginate(min(max($request->integer('par_page', 500), 1), 1000));
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

    /** Le relevé de compte : achats à crédit et versements, avec le solde après chaque ligne. */
    public function releveCompte(Request $request, Client $client, ReleveCompte $releve): JsonResponse
    {
        $data = $request->validate(['du' => ['nullable', 'date'], 'au' => ['nullable', 'date', 'after_or_equal:du']]);

        return response()->json([
            'client' => $client->nom,
            'telephone' => $client->telephone,
            ...$releve->pour($client, isset($data['du']) ? Carbon::parse($data['du']) : null, isset($data['au']) ? Carbon::parse($data['au']) : null),
        ]);
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
            'reglements' => $client->reglements()->encaissements()->with(['caissier:id,name', 'client'])->orderByDesc('numero')->latest()->get()
                ->map(fn (ReglementCredit $r) => $r->recu()),
        ]);
    }

    /**
     * Rendre au client tout ou partie de ce qu'on lui garde : un versement en
     * sens inverse. Son avoir baisse, l'argent sort du tiroir de la séance, et
     * un reçu « Rendu » est édité.
     */
    public function restitution(Request $request, Client $client): JsonResponse
    {
        $avoir = $client->avoir();
        $data = $request->validate([
            'montant' => ['required', 'integer', 'min:1', 'max:'.max(1, $avoir)],
            'moyen_paiement' => ['required', Rule::in(array_values(array_filter(
                array_map(fn (MoyenPaiement $m) => $m->value, MoyenPaiement::cases()),
                fn (string $m) => $m !== MoyenPaiement::CreditClient->value,
            )))],
        ], ['montant.max' => $avoir > 0 ? 'Vous ne lui devez que '.Montant::format($avoir).'.' : 'Vous ne lui devez rien.'], ['montant' => 'montant']);

        $reglement = app(ReglementsCredit::class)->enregistrer($client, $request->user(), -(int) $data['montant'], $data['moyen_paiement'], 'Rendu au client');

        return response()->json(['solde_du' => $client->soldeDu(), 'avoir' => $client->avoir(), 'recu' => $reglement->load(['caissier', 'client'])->recu()], 201);
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

    /**
     * « + » / « − » sur la dette : le client doit [montant] de plus (ancienne
     * dette, crédit hors application) ou de moins (erreur, remise). Aucun
     * argent ne bouge ; le relevé garde la ligne et son motif.
     */
    public function ajusterDette(Request $request, Client $client): JsonResponse
    {
        $du = $client->soldeDu();
        $data = $request->validate([
            'sens' => ['required', Rule::in(['augmenter', 'diminuer'])],
            'montant' => ['required', 'integer', 'min:1', 'max:'.($request->input('sens') === 'diminuer' ? max(1, $du) : 100000000000)],
            'motif' => ['nullable', 'string', 'max:255'],
        ], ['montant.max' => $request->input('sens') === 'diminuer' ? ($du > 0 ? 'Il ne doit que '.Montant::format($du).'.' : 'Il ne doit rien.') : 'Montant trop grand.'], ['montant' => 'montant']);

        $ecart = $data['sens'] === 'diminuer' ? -(int) $data['montant'] : (int) $data['montant'];
        app(ReglementsCredit::class)->ajusterDette($client, $request->user(), $ecart, $data['motif'] ?? null);

        return response()->json(['solde_du' => $client->soldeDu(), 'avoir' => $client->avoir()], 201);
    }

    public function destroy(Client $client): JsonResponse
    {
        $client->delete();

        return response()->json(status: 204);
    }
}
