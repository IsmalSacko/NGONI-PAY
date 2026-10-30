<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArreteTransfert;
use App\Models\OperateurTransfert;
use App\Models\OperationTransfert;
use App\Services\TransfertsArgent;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Le transfert d'argent d'une boutique (cabine Orange Money, Moov Money,
 * Wave…) : ses opérateurs et leurs soldes, les opérations de la journée,
 * l'arrêté du soir et le rapport. Le client n'a pas besoin de l'application :
 * il est un nom et un numéro.
 */
class TransfertController extends Controller
{
    public function __construct(private readonly TransfertsArgent $transferts) {}

    /** Les soldes (tiroir et chaque puce), les opérateurs, et le dernier arrêté. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->etat()]);
    }

    /** Les opérations, la plus récente d'abord : un jour, une période, un client. */
    public function operations(Request $request): JsonResponse
    {
        $f = $request->validate([
            'du' => ['nullable', 'date'], 'au' => ['nullable', 'date'],
            'operateur_id' => ['nullable', 'string'], 'type' => ['nullable', 'string'],
            'recherche' => ['nullable', 'string', 'max:80'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $requete = OperationTransfert::with(['auteur:id,name', 'annuleurPar:id,name'])
            ->when($f['du'] ?? null, fn ($q, $d) => $q->whereDate('jour', '>=', $d))
            ->when($f['au'] ?? null, fn ($q, $d) => $q->whereDate('jour', '<=', $d))
            ->when($f['operateur_id'] ?? null, fn ($q, $id) => $q->where('operateur_id', $id))
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when(filled($f['recherche'] ?? null), function ($q) use ($f): void {
                $mot = trim((string) $f['recherche']);
                $chiffres = preg_replace('/\D+/', '', $mot);
                $q->where(fn ($w) => $w->whereLike('client_nom', "%{$mot}%")->orWhereLike('reference', "%{$mot}%")
                    ->orWhereLike('beneficiaire', "%{$mot}%")
                    ->when($chiffres !== '', fn ($c) => $c->orWhereLike('client_telephone', "%{$chiffres}%")));
            })
            ->orderByDesc('created_at')->orderByDesc('id');

        $page = $requete->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (OperationTransfert $o) => $this->vue($o))->values(),
            'total' => $page->total(),
            'pages' => $page->lastPage(),
        ]);
    }

    /**
     * Les clients déjà vus en transfert, le plus récent d'abord : on tape
     * quelques chiffres du numéro (ou du nom), on choisit, sans tout retaper.
     */
    public function clients(Request $request): JsonResponse
    {
        $mot = trim((string) $request->validate(['recherche' => ['required', 'string', 'min:2', 'max:80']])['recherche']);
        $chiffres = preg_replace('/\D+/', '', $mot);

        $clients = OperationTransfert::whereNotNull('client_telephone')
            ->where(fn ($w) => $w->whereLike('client_nom', "%{$mot}%")
                ->when($chiffres !== '', fn ($c) => $c->orWhereLike('client_telephone', "%{$chiffres}%")))
            ->selectRaw('client_telephone, MAX(client_nom) AS nom, MAX(created_at) AS derniere, COUNT(*) AS nombre')
            ->groupBy('client_telephone')->orderByDesc('derniere')->limit(8)->get();

        return response()->json(['data' => $clients->map(fn ($c) => [
            'telephone' => $c->client_telephone, 'nom' => $c->nom, 'nombre' => (int) $c->nombre,
        ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $operation = $this->transferts->enregistrer($this->valider($request), $request->user());

        return response()->json(['data' => $this->vue($operation->load('auteur:id,name')), 'etat' => $this->etat()], 201);
    }

    public function update(Request $request, OperationTransfert $operation): JsonResponse
    {
        $operation = $this->transferts->modifier($operation, $this->valider($request));

        return response()->json(['data' => $this->vue($operation->load('auteur:id,name')), 'etat' => $this->etat()]);
    }

    public function annuler(Request $request, OperationTransfert $operation): JsonResponse
    {
        $operation = $this->transferts->annuler($operation, $request->user());

        return response()->json(['data' => $this->vue($operation->load(['auteur:id,name', 'annuleurPar:id,name'])), 'etat' => $this->etat()]);
    }

    // ------------------------------------------------------------ opérateurs

    /** Un opérateur de plus ; son solde actuel, s'il est donné, devient le solde de départ de sa puce. */
    public function creerOperateur(Request $request): JsonResponse
    {
        $data = $this->validerOperateur($request);
        $solde = $request->validate(['solde' => ['nullable', 'integer', 'min:0', 'max:100000000000']])['solde'] ?? null;
        $operateur = OperateurTransfert::create([...$data, 'ordre' => (int) OperateurTransfert::max('ordre') + 1]);
        if ($solde) {
            OperationTransfert::create([
                'user_id' => $request->user()->id, 'operateur_id' => $operateur->id, 'operateur_nom' => $operateur->nom,
                'type' => OperationTransfert::AJUSTEMENT, 'montant' => (int) $solde, 'effet_puce' => (int) $solde,
                'jour' => today()->toDateString(), 'note' => 'Solde de départ',
            ]);
        }

        return response()->json(['data' => $operateur->only(['id', 'nom', 'ordre']), 'etat' => $this->etat()], 201);
    }

    /** Renommer : les opérations passées gardent l'ancien nom, tel qu'il était. */
    public function modifierOperateur(Request $request, OperateurTransfert $operateur): JsonResponse
    {
        $operateur->update($this->validerOperateur($request, $operateur));

        return response()->json(['data' => $operateur->only(['id', 'nom', 'ordre']), 'etat' => $this->etat()]);
    }

    /** Retiré de la liste, ses opérations restent. Pas tant que sa puce a de l'argent : il disparaîtrait des comptes. */
    public function supprimerOperateur(OperateurTransfert $operateur): JsonResponse
    {
        $solde = $this->transferts->soldes()['puces'][$operateur->id] ?? 0;
        if ($solde !== 0) {
            throw ValidationException::withMessages([
                'operateur' => "La puce {$operateur->nom} n’est pas à zéro : videz-la, ou indiquez son solde en faisant vos comptes, avant de la retirer.",
            ]);
        }
        $operateur->delete();

        return response()->json(['etat' => $this->etat()]);
    }

    // --------------------------------------------------------- arrêté, rapport

    public function arreter(Request $request): JsonResponse
    {
        $data = $request->validate([
            'especes' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'puces' => ['present', 'array'],
            'puces.*' => ['required', 'integer', 'min:0', 'max:100000000000'],
        ]);
        $arrete = $this->transferts->arreter((int) $data['especes'], array_map('intval', $data['puces']), $request->user());

        return response()->json(['data' => $this->vueArrete($arrete->load('auteur:id,name')), 'etat' => $this->etat()], 201);
    }

    public function arretes(): JsonResponse
    {
        return response()->json(['data' => ArreteTransfert::with('auteur:id,name')->latest()->limit(60)->get()
            ->map(fn (ArreteTransfert $a) => $this->vueArrete($a))->values()]);
    }

    public function rapport(Request $request): JsonResponse
    {
        $f = $request->validate(['du' => ['required', 'date'], 'au' => ['required', 'date', 'after_or_equal:du']]);

        return response()->json(['data' => [
            ...$this->transferts->rapport(Carbon::parse($f['du']), Carbon::parse($f['au'])),
            'soldes' => $this->etat(),
        ]]);
    }

    // ------------------------------------------------------------------ vues

    /** @return array<string, mixed> */
    private function etat(): array
    {
        $soldes = $this->transferts->soldes();
        $dernier = ArreteTransfert::with('auteur:id,name')->latest()->first();
        $jour = today()->toDateString();

        return [
            'especes' => $soldes['especes'],
            'operateurs' => OperateurTransfert::orderBy('ordre')->orderBy('nom')->get()
                ->map(fn (OperateurTransfert $o) => ['id' => $o->id, 'nom' => $o->nom, 'ordre' => $o->ordre, 'solde' => $soldes['puces'][$o->id] ?? 0])->values(),
            // Une puce d'un opérateur retiré, qui a encore de l'argent : elle reste visible.
            'retires' => OperateurTransfert::onlyTrashed()->get()
                ->filter(fn (OperateurTransfert $o) => ($soldes['puces'][$o->id] ?? 0) !== 0)
                ->map(fn (OperateurTransfert $o) => ['id' => $o->id, 'nom' => $o->nom, 'solde' => $soldes['puces'][$o->id]])->values(),
            'dernier_arrete' => $dernier ? $this->vueArrete($dernier) : null,
            // Rien encore : l'application propose de compter les soldes de départ.
            'a_demarrer' => ! OperationTransfert::exists(),
            'aujourdhui' => [
                'nombre' => OperationTransfert::valides()->whereIn('type', OperationTransfert::CLIENT)->whereDate('jour', $jour)->count(),
                'commission' => (int) OperationTransfert::valides()->whereDate('jour', $jour)->sum('commission'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function vue(OperationTransfert $o): array
    {
        return [
            'id' => $o->id, 'type' => $o->type, 'operateur_id' => $o->operateur_id, 'operateur_nom' => $o->operateur_nom,
            'montant' => $o->montant, 'commission' => $o->commission, 'commission_especes' => $o->commission_especes,
            'via_tiroir' => $o->via_tiroir, 'effet_puce' => $o->effet_puce, 'effet_especes' => $o->effet_especes,
            'client_nom' => $o->client_nom, 'client_telephone' => $o->client_telephone, 'beneficiaire' => $o->beneficiaire,
            'reference' => $o->reference, 'note' => $o->note, 'jour' => $o->jour->toDateString(),
            'le' => $o->created_at?->toIso8601String(), 'par' => $o->auteur?->name,
            'annulee_le' => $o->annulee_le?->toIso8601String(), 'annulee_par' => $o->annuleurPar?->name,
            'modifiable' => $this->transferts->modifiable($o),
        ];
    }

    /** @return array<string, mixed> */
    private function vueArrete(ArreteTransfert $a): array
    {
        return [
            'id' => $a->id, 'le' => $a->created_at?->toIso8601String(), 'par' => $a->auteur?->name,
            'especes_theorique' => $a->especes_theorique, 'especes_compte' => $a->especes_compte, 'puces' => $a->puces,
        ];
    }

    /** @return array<string, mixed> */
    private function valider(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in([...OperationTransfert::CLIENT, ...OperationTransfert::FONDS])],
            'operateur_id' => ['nullable', 'string'],
            'montant' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'commission' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'commission_especes' => ['nullable', 'boolean'],
            'via_tiroir' => ['nullable', 'boolean'],
            'client_nom' => ['nullable', 'string', 'max:80'],
            'client_telephone' => ['nullable', 'string', 'max:30'],
            'beneficiaire' => ['nullable', 'string', 'max:80'],
            'reference' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:200'],
        ], ['montant.min' => 'Indiquez le montant.', 'montant.required' => 'Indiquez le montant.']);
    }

    /** @return array{nom: string} */
    private function validerOperateur(Request $request, ?OperateurTransfert $operateur = null): array
    {
        $data = $request->validate(['nom' => ['required', 'string', 'max:40']]);
        $data['nom'] = trim($data['nom']);
        $request->merge($data)->validate([
            'nom' => [Rule::unique('operateurs_transfert', 'nom')->where('boutique_id', app(TenantContext::class)->boutiqueId())
                ->whereNull('deleted_at')->ignore($operateur?->id)],
        ], ['nom.unique' => 'Cet opérateur existe déjà.']);

        return $data;
    }
}
