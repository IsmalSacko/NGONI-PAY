<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coffre;
use App\Models\MouvementCoffre;
use App\Services\Coffres;
use App\Services\Journee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Les coffres de la boutique (fonction Pro) : mettre de côté une partie de la
 * recette, à la main ou chaque soir (une somme par jour, ou une part des
 * ventes encaissées). Ngoni compte ; il ne déplace pas l'argent.
 */
class CoffreController extends Controller
{
    public function __construct(private readonly Coffres $coffres, private readonly Journee $journee) {}

    /** Les coffres, leur règle et leur solde ; le total mis de côté. */
    public function index(): JsonResponse
    {
        $liste = Coffre::orderBy('created_at')->orderBy('id')->get();
        $soldes = $this->coffres->soldes();

        return response()->json([
            'data' => $liste->map(fn (Coffre $c) => $this->vue($c, $soldes[$c->id] ?? 0))->values(),
            'total' => array_sum(array_map(fn (Coffre $c) => $soldes[$c->id] ?? 0, $liste->all())),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $coffre = Coffre::create([...$this->regle($request), 'nom' => $this->nom($request),
            'regle_depuis' => $this->journee->courante()->toDateString()]);

        return response()->json(['data' => $this->vue($coffre, 0)], 201);
    }

    /** Le nom, ou la façon de le remplir : modifiable à tout moment. */
    public function update(Request $request, Coffre $coffre): JsonResponse
    {
        $regle = $this->regle($request);
        $change = $regle['mode'] !== $coffre->mode || $regle['montant_fixe'] !== $coffre->montant_fixe
            || (float) $regle['pourcentage'] !== (float) $coffre->pourcentage;
        $coffre->update([
            ...$regle,
            'nom' => $this->nom($request),
            // Une règle changée vaut à partir de la journée en cours.
            'regle_depuis' => $change ? $this->journee->courante()->toDateString() : $coffre->regle_depuis,
        ]);

        return response()->json(['data' => $this->vue($coffre->fresh(), $this->coffres->solde($coffre))]);
    }

    /** Un coffre vide seulement : l'argent mis de côté ne disparaît pas d'un geste. */
    public function destroy(Coffre $coffre): JsonResponse
    {
        if ($this->coffres->solde($coffre) !== 0) {
            throw ValidationException::withMessages(['coffre' => ['Retirez d’abord l’argent du coffre, puis supprimez-le.']]);
        }
        DB::transaction(function () use ($coffre): void {
            $coffre->mouvements()->delete();
            $coffre->delete();
        });

        return response()->json(['ok' => true]);
    }

    /** L'historique d'un coffre, le plus récent d'abord. */
    public function mouvements(Coffre $coffre): JsonResponse
    {
        $liste = $coffre->mouvements()->with('auteur:id,name')->orderByDesc('jour')->orderByDesc('created_at')->limit(200)->get();

        return response()->json([
            'data' => $liste->map(fn (MouvementCoffre $m) => [
                'id' => $m->id, 'type' => $m->type, 'montant' => $m->montant, 'jour' => $m->jour->toDateString(),
                'base' => $m->base, 'par' => $m->auteur?->name, 'le' => $m->created_at?->toIso8601String(),
            ])->values(),
            'solde' => $this->coffres->solde($coffre),
        ]);
    }

    public function ajouter(Request $request, Coffre $coffre): JsonResponse
    {
        $this->coffres->ajouter($coffre, $this->montant($request), $request->user());

        return response()->json(['data' => ['solde' => $this->coffres->solde($coffre)]], 201);
    }

    public function retirer(Request $request, Coffre $coffre): JsonResponse
    {
        $this->coffres->retirer($coffre, $this->montant($request), $request->user());

        return response()->json(['data' => ['solde' => $this->coffres->solde($coffre)]], 201);
    }

    /** Ce que le soir mettra de côté aujourd'hui : le rappel de la fermeture de caisse. */
    public function aujourdhui(): JsonResponse
    {
        return response()->json(['data' => $this->coffres->prevision($this->journee->courante())]);
    }

    /** @return array{mode: string, montant_fixe: int|null, pourcentage: float|null} */
    private function regle(Request $request): array
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(Coffre::MODES)],
            'montant_fixe' => ['nullable', 'required_if:mode,'.Coffre::FIXE, 'integer', 'min:1', 'max:100000000000'],
            'pourcentage' => ['nullable', 'required_if:mode,'.Coffre::POURCENTAGE, 'numeric', 'min:0.5', 'max:100'],
        ], [], ['montant_fixe' => 'somme par jour', 'pourcentage' => 'part des ventes']);

        // Seule la valeur de la règle choisie est gardée.
        return [
            'mode' => $data['mode'],
            'montant_fixe' => $data['mode'] === Coffre::FIXE ? (int) $data['montant_fixe'] : null,
            'pourcentage' => $data['mode'] === Coffre::POURCENTAGE ? round((float) $data['pourcentage'], 2) : null,
        ];
    }

    private function nom(Request $request): string
    {
        return trim((string) $request->validate(['nom' => ['required', 'string', 'max:40']], [], ['nom' => 'nom'])['nom']);
    }

    private function montant(Request $request): int
    {
        return (int) $request->validate(['montant' => ['required', 'integer', 'min:1', 'max:100000000000']])['montant'];
    }

    /** @return array<string, mixed> */
    private function vue(Coffre $c, int $solde): array
    {
        return ['id' => $c->id, 'nom' => $c->nom, 'mode' => $c->mode, 'montant_fixe' => $c->montant_fixe,
            'pourcentage' => $c->pourcentage, 'solde' => $solde];
    }
}
