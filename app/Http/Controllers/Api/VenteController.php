<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\MoyenPaiement;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ExigeAppPourLesFractions;
use App\Models\Vente;
use App\Services\VenteService;
use App\Support\Presence\Appareil;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VenteController extends Controller
{
    public function __construct(private readonly VenteService $ventes) {}

    /**
     * Ventes, par pages de 30, les plus récentes d'abord. Facultatif : une
     * période (`du`, `au`, jours d'affaires) et une recherche (n° de ticket ou
     * de facture, client, article). Chaque page dit aussi, pour ses jours, le
     * nombre et le total des ventes valides de toute la recherche — l'en-tête
     * « Aujourd'hui · 12 ventes · 85 000 F » ne dépend pas de ce qui est chargé.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'du' => ['nullable', 'date_format:Y-m-d'],
            'au' => ['nullable', 'date_format:Y-m-d'],
            'recherche' => ['nullable', 'string', 'max:80'],
        ]);

        $filtre = function ($query) use ($request) {
            // Sans view_all (caissier) : ses propres ventes seulement.
            if (! $request->user()->can('ventes.view_all')) {
                $query->where('ventes.user_id', $request->user()->id);
            }
            if ($request->filled('depuis')) {
                $query->where('ventes.created_at', '>=', $request->date('depuis'));
            }
            if ($request->filled('du')) {
                $query->where('ventes.jour_affaire', '>=', $request->string('du'));
            }
            if ($request->filled('au')) {
                $query->where('ventes.jour_affaire', '<=', $request->string('au'));
            }
            if ($request->filled('recherche')) {
                $texte = trim((string) $request->string('recherche'));
                $terme = '%'.$texte.'%';
                $numero = ltrim($texte, '#0');
                $query->where(function ($q) use ($terme, $numero): void {
                    $q->where('ventes.numero_facture', 'like', $terme)
                        ->when(ctype_digit($numero), fn ($q) => $q->orWhere('ventes.numero', (int) $numero))
                        ->orWhereHas('client', fn ($c) => $c->where('nom', 'like', $terme)->orWhere('telephone', 'like', $terme))
                        ->orWhereHas('lignes', fn ($l) => $l->where('nom_produit', 'like', $terme));
                });
            }

            return $query;
        };

        $page = $filtre(Vente::with(['lignes', 'client', 'caissier']))->latest()->orderByDesc('numero')->paginate(30);

        $jours = collect($page->items())->map(fn (Vente $v) => $v->jour_affaire?->toDateString())->filter()->unique()->values();
        $resume = $jours->isEmpty() ? collect() : $filtre(Vente::query())->valides()
            ->whereIn('ventes.jour_affaire', $jours->all())
            ->selectRaw('ventes.jour_affaire as jour, COUNT(*) as nombre, SUM(ventes.total) as total')
            ->groupBy('ventes.jour_affaire')->get()
            ->map(fn ($r) => ['date' => substr((string) $r->jour, 0, 10), 'nombre' => (int) $r->nombre, 'total' => (int) $r->total])
            ->values();

        return response()->json([...$page->toArray(), 'jours' => $resume]);
    }

    public function show(Request $request, Vente $vente): JsonResponse
    {
        abort_unless($vente->user_id === $request->user()->id || $request->user()->can('ventes.view_all'), 404);

        return response()->json($vente->load(['lignes', 'client', 'caissier']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference_locale' => ['nullable', 'uuid'],
            // Un client de CETTE boutique : `exists` seul accepterait celui d'une autre.
            'client_id' => ['nullable', 'uuid', Rule::exists('clients', 'id')
                ->where('boutique_id', app(TenantContext::class)->boutiqueId())->whereNull('deleted_at')],
            'lignes' => ['required', 'array', 'min:1'],
            // Ligne du catalogue : produit_id (le prix est relu côté serveur).
            // Ligne libre : libellé + prix saisi, sans produit ni stock —
            // prestation, acompte, article hors catalogue.
            'lignes.*.produit_id' => ['nullable', 'uuid', 'required_without:lignes.*.libelle'],
            'lignes.*.libelle' => ['nullable', 'string', 'max:120', 'required_without:lignes.*.produit_id'],
            'lignes.*.prix_unitaire' => ['nullable', 'integer', 'min:1', 'max:1000000000', 'required_with:lignes.*.libelle'],
            'lignes.*.taux_tva' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Au poids ou au demi : jusqu'à trois décimales (1,250 kg).
            'lignes.*.quantite' => ['required', 'numeric', 'min:0.001', 'max:1000000', 'decimal:0,3'],
            // Pharmacie : le palier vendu (boîte, plaquette, comprimé).
            'lignes.*.palier' => ['nullable', 'string', 'max:40'],
            // Vente en gros : « gros » pour tout le panier (droit de remise).
            'tarif' => ['nullable', 'in:detail,gros'],
            'ordonnance' => ['nullable', 'array'],
            'ordonnance.prescripteur' => ['nullable', 'string', 'max:120'],
            'ordonnance.numero' => ['nullable', 'string', 'max:60'],
            'ordonnance.patient' => ['nullable', 'string', 'max:120'],
            'remise' => ['nullable', 'integer', 'min:0'],
            'moyen_paiement' => ['required', Rule::enum(MoyenPaiement::class)],
            'montant_recu' => ['nullable', 'integer', 'min:0'],
            // Payé maintenant ; le reste est la dette du client. Absent : tout
            // payé (ou rien, pour « crédit client »), comme les anciennes versions.
            'montant_paye' => ['nullable', 'integer', 'min:0'],
            'vendue_hors_ligne' => ['nullable', 'boolean'],
            // Remise de fidélité demandée : le serveur la calcule lui-même.
            'remise_fidelite' => ['nullable', 'boolean'],
        ]);

        if (($data['remise_fidelite'] ?? false) && empty($data['client_id'])) {
            throw ValidationException::withMessages(['client_id' => ['La remise de fidélité va à un client : choisissez-le.']]);
        }

        // Ce que le vendeur n'a pas le droit de faire (Permissions::DROITS).
        // Une vente rejouée après une coupure est refusée de même : l'application
        // la met de côté avec ce motif, sans bloquer les suivantes.
        $user = $request->user();
        if (($data['remise_fidelite'] ?? false) && ! $user->can('ventes.remise')) {
            // La remise fidélité, le serveur la calcule : sans programme, celle
            // saisie resterait (VenteService) — pas pour qui n'a pas ce droit.
            $data['remise'] = 0;
        }
        $refus = match (true) {
            ($data['remise'] ?? 0) > 0 && ! ($data['remise_fidelite'] ?? false) && ! $user->can('ventes.remise') => 'Vous n’avez pas le droit de faire des remises.',
            collect($data['lignes'])->contains(fn ($l) => empty($l['produit_id'])) && ! $user->can('ventes.montant_libre') => 'Vous n’avez pas le droit de vendre au montant libre.',
            default => null,
        };
        abort_if($refus !== null, 403, $refus);

        // Une application d'avant la vente en gros a affiché — et encaissé — le
        // prix de détail : sa vente le garde, même rejouée plus tard.
        $version = Appareil::depuisRequete($request)->version;
        if ($version !== null && version_compare(explode('+', $version)[0], ExigeAppPourLesFractions::VERSION_GROS, '<')) {
            $data['sans_prix_de_gros'] = true;
        }

        $vente = $this->ventes->encaisser($data, $user);

        return response()->json($vente->load(['client', 'caissier']), 201);
    }

    /** Annulation tracée (gérant, admin) : motif obligatoire, stock remis. */
    public function annuler(Request $request, Vente $vente): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']], [], ['motif' => 'motif']);

        return response()->json($this->ventes->annuler($vente, $request->user(), $data['motif']));
    }
}
