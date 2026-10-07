<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\MoyenPaiement;
use App\Http\Controllers\Controller;
use App\Models\CommandeRestaurant;
use App\Models\LigneCommandeRestaurant;
use App\Models\ReservationRestaurant;
use App\Services\CommandesRestaurant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Restaurant : les commandes (prise, cuisine, service, addition). */
class CommandeRestaurantController extends Controller
{
    public function __construct(private readonly CommandesRestaurant $commandes) {}

    /**
     * Filtre : en_cours (par défaut), a_payer, en_cuisine, pretes, emporter
     * (à emporter et livraisons), terminees, annulees, toutes ; recherche :
     * numéro, table, nom ou téléphone du client.
     */
    public function index(Request $request): JsonResponse
    {
        $this->commandes->boutique();
        $q = trim((string) $request->query('q', ''));
        $requete = CommandeRestaurant::with('lignes', 'client', 'serveur')
            ->when($q === '', fn ($r) => $this->filtrer($r, (string) $request->query('filtre', 'en_cours')))
            ->when($request->query('client_id'), fn ($r, string $id) => $r->where('client_id', $id))
            ->when($q !== '', function ($r) use ($q) {
                $chiffres = preg_replace('/\D/', '', $q);
                $r->where(function ($w) use ($q, $chiffres) {
                    if (strlen($chiffres) === 6) {
                        $w->orWhere('numero', (int) $chiffres);
                    }
                    $w->orWhere('table', 'like', "%{$q}%")
                        ->orWhereHas('client', fn ($c) => $c->where('nom', 'like', "%{$q}%")
                            ->when(strlen($chiffres) >= 4, fn ($c) => $c->orWhere('telephone', 'like', "%{$chiffres}%")));
                });
            })
            ->orderByRaw('case when terminee_le is null and statut != ? then 0 else 1 end', [CommandeRestaurant::ANNULEE])
            ->orderByDesc('created_at');

        return response()->json(['data' => $requete->limit(200)->get()->map(fn ($c) => $this->json($c))->values()]);
    }

    /** @param  Builder<CommandeRestaurant>  $r */
    private function filtrer(Builder $r, string $filtre): Builder
    {
        $enCours = fn ($q) => $q->whereNull('terminee_le')->where('statut', '!=', CommandeRestaurant::ANNULEE);

        return match ($filtre) {
            'a_payer' => $r->where('statut', CommandeRestaurant::OUVERTE)->where('total', '>', 0),
            'en_cuisine' => $enCours($r)->whereHas('lignes', fn ($l) => $l->where('etat', LigneCommandeRestaurant::EN_CUISINE)),
            'pretes' => $enCours($r)->whereHas('lignes', fn ($l) => $l->where('etat', LigneCommandeRestaurant::PRETE)),
            'emporter' => $enCours($r)->whereIn('type', ['emporter', 'livraison']),
            'terminees' => $r->whereNotNull('terminee_le')->where('created_at', '>=', now()->subDays(30)),
            'annulees' => $r->where('statut', CommandeRestaurant::ANNULEE)->where('created_at', '>=', now()->subDays(30)),
            'toutes' => $r->where('created_at', '>=', now()->subDays(30)),
            default => $enCours($r),
        };
    }

    /** Les chiffres du service en cours. */
    public function compteurs(): JsonResponse
    {
        $this->commandes->boutique();
        $enCours = fn () => CommandeRestaurant::whereNull('terminee_le')->where('statut', '!=', CommandeRestaurant::ANNULEE);

        return response()->json(['data' => [
            'en_cours' => $enCours()->count(),
            'a_payer' => CommandeRestaurant::where('statut', CommandeRestaurant::OUVERTE)->where('total', '>', 0)->count(),
            'en_cuisine' => (int) LigneCommandeRestaurant::whereIn('commande_id', $enCours()->select('id'))->where('etat', LigneCommandeRestaurant::EN_CUISINE)->sum('quantite'),
            'pretes' => (int) LigneCommandeRestaurant::whereIn('commande_id', $enCours()->select('id'))->where('etat', LigneCommandeRestaurant::PRETE)->sum('quantite'),
            'emporter' => $enCours()->whereIn('type', ['emporter', 'livraison'])->count(),
            'reservations' => ReservationRestaurant::where('statut', ReservationRestaurant::PREVUE)->whereBetween('le', [now()->startOfDay(), now()->endOfDay()])->count(),
        ]]);
    }

    /** L'écran cuisine : les plats envoyés et pas encore servis, envoi par envoi, du plus ancien au plus récent. */
    public function cuisine(): JsonResponse
    {
        $this->commandes->boutique();
        $lignes = LigneCommandeRestaurant::with('commande')
            ->whereIn('etat', [LigneCommandeRestaurant::EN_CUISINE, LigneCommandeRestaurant::PRETE])
            ->whereHas('commande', fn ($c) => $c->where('statut', '!=', CommandeRestaurant::ANNULEE))
            ->orderBy('envoyee_le')->orderBy('ordre')->get();

        return response()->json(['data' => $lignes->groupBy(fn ($l) => $l->commande_id.'#'.$l->envoi)->map(function ($groupe) {
            $c = $groupe->first()->commande;

            return [
                'commande_id' => $c->id, 'numero' => (string) $c->numero, 'type' => $c->type, 'table' => $c->table,
                'envoi' => $groupe->first()->envoi, 'envoyee_le' => $groupe->first()->envoyee_le?->toIso8601String(),
                'heure_prevue' => $c->heure_prevue?->toIso8601String(),
                'lignes' => $groupe->map(fn ($l) => $this->ligne($l))->values(),
            ];
        })->values()]);
    }

    public function show(CommandeRestaurant $commande): JsonResponse
    {
        $this->commandes->boutique();

        return response()->json(['data' => $this->json($commande)]);
    }

    /** Nouvelle commande ; avec [paiement] : payée tout de suite (au comptoir). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference_locale' => ['nullable', 'uuid'],
            'type' => ['nullable', Rule::in(CommandeRestaurant::TYPES)],
            'telephone' => ['nullable', 'boolean'],
            'table' => ['nullable', 'string', 'max:40'],
            'couverts' => ['nullable', 'integer', 'min:1', 'max:500'],
            'client_id' => ['nullable', 'uuid'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'heure_prevue' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'acompte' => ['nullable', 'integer', 'min:0'],
            'moyen_acompte' => ['nullable', Rule::enum(MoyenPaiement::class)],
            'envoyer' => ['nullable', 'boolean'],
            ...$this->reglesLignes(),
            // Une table peut s'ouvrir avant de commander (arrivée d'une réservation).
            'lignes' => ['nullable', 'array', 'max:200'],
            'paiement' => ['nullable', 'array'],
            ...$this->reglesPaiement('paiement.'),
        ]);
        $commande = $this->commandes->creer($data, $request->user());
        $vente = null;
        if (! empty($data['paiement']) && $commande->reste() > 0) {
            ['commande' => $commande, 'vente' => $vente] = $this->commandes->payer($commande, $data['paiement'], $request->user());
        }

        return response()->json(['data' => $this->json($commande), 'vente_id' => $vente?->id], 201);
    }

    public function ajouter(Request $request, CommandeRestaurant $commande): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate([...$this->reglesLignes(), 'envoyer' => ['nullable', 'boolean']]);

        return response()->json(['data' => $this->json($this->commandes->ajouter($commande, $data['lignes'], $request->user(), (bool) ($data['envoyer'] ?? true)))]);
    }

    public function modifierLigne(Request $request, CommandeRestaurant $commande, LigneCommandeRestaurant $ligne): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate(['quantite' => ['nullable', 'integer', 'min:1', 'max:1000'], 'note' => ['nullable', 'string', 'max:160']]);

        return response()->json(['data' => $this->json($this->commandes->modifierLigne($commande, $ligne, $data['quantite'] ?? null, $data['note'] ?? null))]);
    }

    public function annulerLigne(Request $request, CommandeRestaurant $commande, LigneCommandeRestaurant $ligne): JsonResponse
    {
        $this->commandes->boutique();

        return response()->json(['data' => $this->json($this->commandes->annulerLigne($commande, $ligne, $request->user()))]);
    }

    /** Les plats en attente partent en cuisine ; rend aussi l'envoi, pour imprimer le bon. */
    public function envoyer(Request $request, CommandeRestaurant $commande): JsonResponse
    {
        $this->commandes->boutique();
        $lignes = $this->commandes->envoyerEnCuisine($commande, $request->user());

        return response()->json(['data' => $this->json($commande->fresh()), 'envoi' => $lignes->first()?->envoi, 'lignes' => $lignes->map(fn ($l) => $this->ligne($l))->values()]);
    }

    public function prets(Request $request, CommandeRestaurant $commande): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate(['lignes' => ['nullable', 'array'], 'lignes.*' => ['uuid']]);

        return response()->json(['data' => $this->json($this->commandes->marquerPrets($commande, $data['lignes'] ?? null))]);
    }

    public function servir(Request $request, CommandeRestaurant $commande): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate(['lignes' => ['nullable', 'array'], 'lignes.*' => ['uuid']]);

        return response()->json(['data' => $this->json($this->commandes->servir($commande, $data['lignes'] ?? null, $request->user()))]);
    }

    /** L'addition (toute, ou les plats choisis) : rend la commande et la vente, pour son ticket. */
    public function payer(Request $request, CommandeRestaurant $commande): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate($this->reglesPaiement(''));
        ['commande' => $commande, 'vente' => $vente] = $this->commandes->payer($commande, $data, $request->user());

        return response()->json(['data' => $this->json($commande), 'vente_id' => $vente?->id]);
    }

    public function annuler(Request $request, CommandeRestaurant $commande): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate(['motif' => ['nullable', 'string', 'max:255'], 'rembourser' => ['nullable', 'boolean']]);

        return response()->json(['data' => $this->json($this->commandes->annuler($commande, $data['motif'] ?? null, (bool) ($data['rembourser'] ?? false), $request->user()))]);
    }

    public function transferer(Request $request, CommandeRestaurant $commande): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate(['table' => ['required', 'string', 'max:40']]);

        return response()->json(['data' => $this->json($this->commandes->transferer($commande, $data['table'], $request->user()))]);
    }

    public function fusionner(Request $request, CommandeRestaurant $commande): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate(['autre_id' => ['required', 'uuid']]);
        $autre = CommandeRestaurant::findOrFail($data['autre_id']);

        return response()->json(['data' => $this->json($this->commandes->fusionner($commande, $autre, $request->user()))]);
    }

    /** @return array<string, mixed> */
    private function reglesLignes(): array
    {
        return [
            'lignes' => ['required', 'array', 'min:1', 'max:200'],
            'lignes.*.produit_id' => ['required', 'uuid'],
            'lignes.*.quantite' => ['required', 'integer', 'min:1', 'max:1000'],
            'lignes.*.options' => ['nullable', 'array', 'max:20'],
            'lignes.*.options.*' => ['uuid'],
            'lignes.*.composition' => ['nullable', 'array', 'max:10'],
            'lignes.*.composition.*' => ['uuid'],
            'lignes.*.note' => ['nullable', 'string', 'max:160'],
        ];
    }

    /** @return array<string, mixed> */
    private function reglesPaiement(string $prefixe): array
    {
        $requis = $prefixe === '' ? 'required' : 'required_with:paiement';

        return [
            "{$prefixe}moyen_paiement" => [$requis, Rule::enum(MoyenPaiement::class)],
            "{$prefixe}montant_donne" => ['nullable', 'integer', 'min:0'],
            "{$prefixe}pourboire" => ['nullable', 'integer', 'min:0'],
            "{$prefixe}credit" => ['nullable', 'boolean'],
            "{$prefixe}lignes" => ['nullable', 'array'],
            "{$prefixe}lignes.*" => ['uuid'],
        ];
    }

    /** @return array<string, mixed> */
    private function ligne(LigneCommandeRestaurant $l): array
    {
        return [
            'id' => $l->id, 'produit_id' => $l->produit_id, 'nom' => $l->nom, 'quantite' => $l->quantite,
            'prix_unitaire' => $l->prix_unitaire, 'total_ligne' => $l->total_ligne, 'options' => $l->options ?? [],
            'composition' => $l->composition ?? [], 'note' => $l->note, 'etat' => $l->etat, 'envoi' => $l->envoi,
            'envoyee_le' => $l->envoyee_le?->toIso8601String(), 'payee' => $l->vente_id !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function json(CommandeRestaurant $c): array
    {
        $c->loadMissing('lignes', 'client', 'serveur');

        return [
            'id' => $c->id,
            'numero' => (string) $c->numero,
            'type' => $c->type,
            'telephone' => $c->telephone,
            'table' => $c->table,
            'couverts' => $c->couverts,
            'adresse' => $c->adresse,
            'heure_prevue' => $c->heure_prevue?->toIso8601String(),
            'statut' => $c->statut,
            'en_cours' => $c->enCours(),
            'total' => $c->total,
            'paye' => $c->paye,
            'acompte' => $c->acompte,
            'moyen_acompte' => $c->moyen_acompte,
            'reste' => $c->reste(),
            'pourboire' => $c->pourboire,
            'envois' => $c->envois,
            'notes' => $c->notes,
            'motif_annulation' => $c->motif_annulation,
            'cree_le' => $c->created_at?->toIso8601String(),
            'payee_le' => $c->payee_le?->toIso8601String(),
            'terminee_le' => $c->terminee_le?->toIso8601String(),
            'serveur' => $c->serveur?->name,
            'historique' => $c->historique ?? [],
            'client' => $c->client === null ? null : ['id' => $c->client->id, 'nom' => $c->client->nom, 'telephone' => $c->client->telephone],
            'lignes' => $c->lignes->map(fn ($l) => $this->ligne($l))->values(),
        ];
    }
}
