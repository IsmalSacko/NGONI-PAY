<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\MoyenPaiement;
use App\Http\Controllers\Controller;
use App\Models\FormuleRestaurant;
use App\Models\IngredientRestaurant;
use App\Models\OptionRestaurant;
use App\Models\Produit;
use App\Models\RecetteRestaurant;
use App\Models\ReservationRestaurant;
use App\Models\TableRestaurant;
use App\Services\CommandesRestaurant;
use App\Services\Depenses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Restaurant : la salle (tables, réservations), la carte (options, formules,
 * recettes et coût de revient) et le stock des ingrédients.
 */
class GestionRestaurantController extends Controller
{
    public function __construct(private readonly CommandesRestaurant $commandes) {}

    // ───── Tables ─────

    public function tables(): JsonResponse
    {
        $this->commandes->boutique();

        return response()->json(['data' => TableRestaurant::orderBy('ordre')->orderBy('nom')->get(['id', 'nom', 'zone', 'places', 'ordre'])]);
    }

    public function creerTable(Request $request): JsonResponse
    {
        $this->commandes->boutique();
        $data = $this->validerTable($request);

        return response()->json(['data' => TableRestaurant::create([...$data, 'ordre' => (int) TableRestaurant::max('ordre') + 1])], 201);
    }

    public function modifierTable(Request $request, TableRestaurant $table): JsonResponse
    {
        $this->commandes->boutique();
        $table->update($this->validerTable($request));

        return response()->json(['data' => $table]);
    }

    public function supprimerTable(TableRestaurant $table): JsonResponse
    {
        $this->commandes->boutique();
        $table->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function validerTable(Request $request): array
    {
        return $request->validate([
            'nom' => ['required', 'string', 'max:40'],
            'zone' => ['nullable', 'string', 'max:40'],
            'places' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
    }

    // ───── Réservations ─────

    /** Les réservations d'un jour (?jour=AAAA-MM-JJ, par défaut aujourd'hui), par heure. */
    public function reservations(Request $request): JsonResponse
    {
        $this->commandes->boutique();
        $jour = Carbon::parse($request->query('jour', now()->toDateString()));

        return response()->json(['data' => ReservationRestaurant::whereBetween('le', [$jour->copy()->startOfDay(), $jour->copy()->endOfDay()])
            ->orderBy('le')->get()->map(fn ($r) => $this->reservation($r))->values()]);
    }

    public function reserver(Request $request): JsonResponse
    {
        $this->commandes->boutique();

        return response()->json(['data' => $this->reservation(ReservationRestaurant::create($this->validerReservation($request)))], 201);
    }

    public function modifierReservation(Request $request, ReservationRestaurant $reservation): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate([...$this->reglesReservation(), 'statut' => ['nullable', Rule::in([ReservationRestaurant::PREVUE, ReservationRestaurant::ANNULEE])]]);
        $reservation->update($data);

        return response()->json(['data' => $this->reservation($reservation)]);
    }

    /** Les clients arrivent : la commande s'ouvre à leur table. */
    public function arrivee(Request $request, ReservationRestaurant $reservation): JsonResponse
    {
        $this->commandes->boutique();
        if ($reservation->statut !== ReservationRestaurant::PREVUE) {
            throw ValidationException::withMessages(['reservation' => ['Cette réservation n’est plus prévue.']]);
        }
        $data = $request->validate(['table' => ['nullable', 'string', 'max:40']]);
        $commande = $this->commandes->creer([
            'type' => 'sur_place', 'table' => $data['table'] ?? $reservation->table ?? $reservation->nom,
            'couverts' => $reservation->couverts, 'client_id' => $reservation->client_id, 'lignes' => [],
            'notes' => $reservation->note,
        ], $request->user());
        $reservation->update(['statut' => ReservationRestaurant::ARRIVEE, 'commande_id' => $commande->id, 'table' => $commande->table]);

        return response()->json(['data' => $this->reservation($reservation), 'commande_id' => $commande->id]);
    }

    /** @return array<string, mixed> */
    private function reglesReservation(): array
    {
        return [
            'nom' => ['required', 'string', 'max:120'],
            'telephone' => ['nullable', 'string', 'max:40'],
            'client_id' => ['nullable', 'uuid'],
            'le' => ['required', 'date'],
            'couverts' => ['nullable', 'integer', 'min:1', 'max:500'],
            'table' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    private function validerReservation(Request $request): array
    {
        return $request->validate($this->reglesReservation());
    }

    /** @return array<string, mixed> */
    private function reservation(ReservationRestaurant $r): array
    {
        return [
            'id' => $r->id, 'nom' => $r->nom, 'telephone' => $r->telephone, 'client_id' => $r->client_id,
            'le' => $r->le->toIso8601String(), 'couverts' => $r->couverts, 'table' => $r->table, 'note' => $r->note,
            'statut' => $r->statut, 'commande_id' => $r->commande_id,
        ];
    }

    // ───── La carte : options, formules, recettes, coût de revient ─────

    /**
     * La carte telle que la prise de commande en a besoin : chaque article,
     * ses options, sa formule, et son coût de revient (recette × coût des
     * ingrédients) avec la marge.
     */
    public function carte(): JsonResponse
    {
        $this->commandes->boutique();
        $produits = Produit::pourActivite()->where('actif', true)->orderBy('nom')->get(['id', 'nom', 'prix_vente', 'categorie_produit_id']);
        $options = OptionRestaurant::orderBy('ordre')->get()->groupBy('produit_id');
        $formules = FormuleRestaurant::all()->keyBy('produit_id');
        $recettes = RecetteRestaurant::with('ingredient')->get()->groupBy('produit_id');

        return response()->json(['data' => $produits->map(function (Produit $p) use ($options, $formules, $recettes) {
            $recette = $recettes->get($p->id, collect());
            $cout = (int) round($recette->sum(fn ($r) => $r->quantite * ($r->ingredient?->cout_unitaire ?? 0)));

            return [
                'id' => $p->id, 'nom' => $p->nom, 'prix' => (int) $p->prix_vente, 'categorie_id' => $p->categorie_produit_id,
                'options' => $options->get($p->id, collect())->map(fn ($o) => ['id' => $o->id, 'groupe' => $o->groupe, 'nom' => $o->nom, 'prix' => $o->prix])->values(),
                'formule' => ($f = $formules->get($p->id)) === null ? null : $f->etapes,
                'recette' => $recette->map(fn ($r) => [
                    'ingredient_id' => $r->ingredient_id, 'nom' => $r->ingredient?->nom, 'unite' => $r->ingredient?->unite, 'quantite' => $r->quantite,
                ])->values(),
                'cout' => $recette->isEmpty() ? null : $cout,
                'marge' => $recette->isEmpty() ? null : (int) $p->prix_vente - $cout,
            ];
        })->values()]);
    }

    public function ajouterOption(Request $request, Produit $produit): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate(['groupe' => ['nullable', 'string', 'max:40'], 'nom' => ['required', 'string', 'max:60'], 'prix' => ['nullable', 'integer', 'min:0']]);
        $option = OptionRestaurant::create([
            'produit_id' => $produit->id, 'groupe' => $data['groupe'] ?? null, 'nom' => trim($data['nom']), 'prix' => $data['prix'] ?? 0,
            'ordre' => (int) OptionRestaurant::where('produit_id', $produit->id)->max('ordre') + 1,
        ]);

        return response()->json(['data' => $option], 201);
    }

    public function supprimerOption(OptionRestaurant $option): JsonResponse
    {
        $this->commandes->boutique();
        $option->delete();

        return response()->json(['ok' => true]);
    }

    /** La formule d'un article : ses étapes et les plats proposés à chacune ; sans étape, l'article redevient simple. */
    public function formule(Request $request, Produit $produit): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate([
            'etapes' => ['present', 'array', 'max:6'],
            'etapes.*.titre' => ['required', 'string', 'max:40'],
            'etapes.*.produits' => ['required', 'array', 'min:1'],
            'etapes.*.produits.*' => ['uuid'],
        ]);
        if ($data['etapes'] === []) {
            FormuleRestaurant::where('produit_id', $produit->id)->delete();

            return response()->json(['data' => null]);
        }
        $connus = Produit::whereIn('id', collect($data['etapes'])->flatMap(fn ($e) => $e['produits']))->pluck('id');
        $etapes = collect($data['etapes'])->map(fn ($e) => [
            'titre' => trim($e['titre']), 'produits' => array_values(array_filter($e['produits'], fn ($id) => $connus->contains($id) && $id !== $produit->id)),
        ])->all();
        $formule = FormuleRestaurant::updateOrCreate(['produit_id' => $produit->id], ['etapes' => $etapes]);

        return response()->json(['data' => $formule->etapes]);
    }

    /** La recette d'un article : remplace toute la liste (ingrédient, quantité par portion). */
    public function recette(Request $request, Produit $produit): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate([
            'ingredients' => ['present', 'array', 'max:40'],
            'ingredients.*.ingredient_id' => ['required', 'uuid'],
            'ingredients.*.quantite' => ['required', 'numeric', 'gt:0'],
        ]);
        $connus = IngredientRestaurant::whereIn('id', array_column($data['ingredients'], 'ingredient_id'))->pluck('id');
        DB::transaction(function () use ($produit, $data, $connus): void {
            RecetteRestaurant::where('produit_id', $produit->id)->delete();
            foreach ($data['ingredients'] as $i) {
                if ($connus->contains($i['ingredient_id'])) {
                    RecetteRestaurant::create(['produit_id' => $produit->id, 'ingredient_id' => $i['ingredient_id'], 'quantite' => $i['quantite']]);
                }
            }
        });

        return response()->json(['ok' => true]);
    }

    // ───── Ingrédients ─────

    public function ingredients(): JsonResponse
    {
        $this->commandes->boutique();

        return response()->json(['data' => IngredientRestaurant::orderBy('nom')->get()->map(fn ($i) => $this->ingredient($i))->values()]);
    }

    public function creerIngredient(Request $request): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:80'],
            'unite' => ['nullable', 'string', 'max:20'],
            'quantite' => ['nullable', 'numeric', 'min:0'],
            'seuil' => ['nullable', 'numeric', 'min:0'],
            'cout_unitaire' => ['nullable', 'integer', 'min:0'],
        ]);
        $i = IngredientRestaurant::create([
            'nom' => trim($data['nom']), 'unite' => $data['unite'] ?? 'kg', 'quantite' => $data['quantite'] ?? 0,
            'seuil' => $data['seuil'] ?? null, 'cout_unitaire' => $data['cout_unitaire'] ?? 0,
        ]);

        return response()->json(['data' => $this->ingredient($i)], 201);
    }

    public function modifierIngredient(Request $request, IngredientRestaurant $ingredient): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:80'],
            'unite' => ['nullable', 'string', 'max:20'],
            'seuil' => ['nullable', 'numeric', 'min:0'],
            'cout_unitaire' => ['nullable', 'integer', 'min:0'],
        ]);
        $ingredient->update([
            'nom' => trim($data['nom']), 'unite' => $data['unite'] ?? $ingredient->unite, 'seuil' => $data['seuil'] ?? null,
            'cout_unitaire' => $data['cout_unitaire'] ?? $ingredient->cout_unitaire,
        ]);

        return response()->json(['data' => $this->ingredient($ingredient)]);
    }

    public function supprimerIngredient(IngredientRestaurant $ingredient): JsonResponse
    {
        $this->commandes->boutique();
        $ingredient->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Entrée (achat : [montant] payé devient une dépense et fixe le coût
     * unitaire) ou sortie (perte, inventaire) d'un ingrédient.
     */
    public function mouvement(Request $request, IngredientRestaurant $ingredient, Depenses $depenses): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate([
            'quantite' => ['required', 'numeric', 'not_in:0'],
            'montant' => ['nullable', 'integer', 'min:0'],
            'moyen_paiement' => ['nullable', Rule::enum(MoyenPaiement::class)],
        ]);
        $quantite = (float) $data['quantite'];
        $montant = (int) ($data['montant'] ?? 0);
        DB::transaction(function () use ($ingredient, $quantite, $montant, $data, $depenses, $request): void {
            $i = IngredientRestaurant::whereKey($ingredient->id)->lockForUpdate()->firstOrFail();
            $i->update([
                'quantite' => round($i->quantite + $quantite, 3),
                'cout_unitaire' => $quantite > 0 && $montant > 0 ? (int) round($montant / $quantite) : $i->cout_unitaire,
            ]);
            if ($quantite > 0 && $montant > 0) {
                $depenses->depenser(['libelle' => "Achat : {$i->nom}", 'categorie' => 'fournitures', 'montant' => $montant, 'moyen_paiement' => $data['moyen_paiement'] ?? 'especes'], $request->user());
            }
        });

        return response()->json(['data' => $this->ingredient($ingredient->fresh())]);
    }

    /** @return array<string, mixed> */
    private function ingredient(IngredientRestaurant $i): array
    {
        return [
            'id' => $i->id, 'nom' => $i->nom, 'unite' => $i->unite, 'quantite' => $i->quantite, 'seuil' => $i->seuil,
            'cout_unitaire' => $i->cout_unitaire, 'a_racheter' => $i->aRacheter(),
        ];
    }
}
