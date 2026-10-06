<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\MoyenPaiement;
use App\Http\Controllers\Controller;
use App\Models\CommandePressing;
use App\Services\CommandesPressing;
use App\Services\Images;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Pressing : le registre des commandes (dépôt, prête, retrait, annulation). */
class CommandePressingController extends Controller
{
    public function __construct(private readonly CommandesPressing $commandes) {}

    /** Filtre : en_cours, pretes, retard, retirees, annulees ; recherche : numéro, nom, téléphone, prestation. */
    public function index(Request $request): JsonResponse
    {
        $this->commandes->boutique();
        $q = trim((string) $request->query('q', ''));
        $requete = CommandePressing::with('lignes', 'client', 'agent')
            ->when($request->query('filtre'), fn ($r, string $f) => match ($f) {
                'en_cours' => $r->whereIn('statut', [CommandePressing::DEPOSEE, CommandePressing::EN_TRAITEMENT]),
                'pretes' => $r->where('statut', CommandePressing::PRETE),
                'aujourdhui', 'retard', 'abandon' => $this->filtrer($r, $f),
                'a_livrer' => $r->where('statut', CommandePressing::PRETE)->where('livraison', true),
                'retirees' => $r->where('statut', CommandePressing::RETIREE),
                'annulees' => $r->where('statut', CommandePressing::ANNULEE),
                default => $r,
            })
            // Fiche client : ses commandes.
            ->when($request->query('client_id'), fn ($r, string $id) => $r->where('client_id', $id))
            ->when($q !== '', function ($r) use ($q) {
                $chiffres = preg_replace('/\D/', '', $q);
                $r->where(function ($w) use ($q, $chiffres) {
                    if ($chiffres !== '' && strlen($chiffres) <= 6) {
                        $w->orWhere('numero', (int) $chiffres);
                    }
                    $w->orWhere('casier', $q)->orWhereHas('client', fn ($c) => $c->where('nom', 'like', "%{$q}%")
                        ->when($chiffres !== '', fn ($c) => $c->orWhere('telephone', 'like', "%{$chiffres}%")))
                        ->orWhereHas('lignes', fn ($l) => $l->where('service', 'like', "%{$q}%")->orWhere('nom', 'like', "%{$q}%"));
                });
            })
            ->orderByRaw("case when statut in ('deposee','en_traitement','prete') then 0 else 1 end")
            ->orderBy('retrait_prevu_le')
            ->orderByDesc('numero');

        return response()->json(['data' => $requete->limit(200)->get()->map(fn ($c) => $this->json($c))->values()]);
    }

    /** Les alertes du comptoir : à rendre aujourd'hui, en retard, prêtes, non retirées depuis 30 jours. */
    public function compteurs(): JsonResponse
    {
        $this->commandes->boutique();

        return response()->json(['data' => [
            'aujourdhui' => $this->filtrer(CommandePressing::query(), 'aujourdhui')->count(),
            'retard' => $this->filtrer(CommandePressing::query(), 'retard')->count(),
            'abandon' => $this->filtrer(CommandePressing::query(), 'abandon')->count(),
            'pretes' => CommandePressing::where('statut', CommandePressing::PRETE)->count(),
        ]]);
    }

    /**
     * Sur les commandes ouvertes : à rendre aujourd'hui, en retard, ou
     * abandonnées (rendez-vous passé depuis plus de 30 jours).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<CommandePressing>  $r
     * @return \Illuminate\Database\Eloquent\Builder<CommandePressing>
     */
    private function filtrer($r, string $filtre)
    {
        $r->whereIn('statut', CommandePressing::OUVERTES);

        return match ($filtre) {
            'aujourdhui' => $r->whereBetween('retrait_prevu_le', [now()->startOfDay(), now()->endOfDay()]),
            'abandon' => $r->where('retrait_prevu_le', '<', now()->subDays(CommandePressing::JOURS_ABANDON)),
            default => $r->where('retrait_prevu_le', '<', now()),
        };
    }

    public function show(CommandePressing $commande): JsonResponse
    {
        $this->commandes->boutique();

        return response()->json(['data' => $this->json($commande->load('lignes', 'client', 'agent'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference_locale' => ['nullable', 'uuid'],
            'client_id' => ['required', 'uuid'],
            'lignes' => ['required', 'array', 'min:1', 'max:100'],
            'lignes.*.produit_id' => ['required', 'uuid'],
            'lignes.*.service_id' => ['required', 'uuid'],
            'lignes.*.quantite' => ['required', 'integer', 'min:1', 'max:1000'],
            'lignes.*.defauts' => ['nullable', 'string', 'max:255'],
            'express' => ['nullable', 'boolean'],
            'acompte' => ['nullable', 'integer', 'min:0'],
            'moyen_acompte' => ['nullable', Rule::enum(MoyenPaiement::class)],
            'retrait_prevu_le' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'collecte' => ['nullable', 'boolean'],
            'livraison' => ['nullable', 'boolean'],
            'adresse' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->json($this->commandes->deposer($data, $request->user()))], 201);
    }

    public function prete(Request $request, CommandePressing $commande): JsonResponse
    {
        $this->commandes->boutique();

        $data = $request->validate(['casier' => ['nullable', 'string', 'max:40']]);

        return response()->json(['data' => $this->json($this->commandes->marquerPrete($commande, $request->user(), $data['casier'] ?? null)->load('lignes', 'client', 'agent'))]);
    }

    /** Casier ou rayon du linge (offre Pro). */
    public function casier(Request $request, CommandePressing $commande): JsonResponse
    {
        $data = $request->validate(['casier' => ['nullable', 'string', 'max:40']]);

        return response()->json(['data' => $this->json($this->commandes->ranger($commande, $data['casier'] ?? null)->load('lignes', 'client', 'agent'))]);
    }

    /** Photo d'un défaut (offre Pro). */
    public function ajouterPhoto(Request $request, CommandePressing $commande): JsonResponse
    {
        $this->commandes->boutique();
        $request->validate(['photo' => Images::REGLES]);

        return response()->json(['data' => $this->json($this->commandes->ajouterPhoto($commande, $request->file('photo'))->load('lignes', 'client', 'agent'))]);
    }

    public function retirerPhoto(CommandePressing $commande, int $index): JsonResponse
    {
        $this->commandes->boutique();

        return response()->json(['data' => $this->json($this->commandes->retirerPhoto($commande, $index)->load('lignes', 'client', 'agent'))]);
    }

    /** Une photo, servie à la boutique seulement (données du client). */
    public function photo(CommandePressing $commande, int $index): Response
    {
        $this->commandes->boutique();
        $chemin = ($commande->photos ?? [])[$index]['chemin'] ?? null;
        abort_if($chemin === null || ! Storage::disk('local')->exists($chemin), 404);

        return Storage::disk('local')->response($chemin, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** Une étape du travail : lavage, séchage, repassage, contrôle. */
    public function etape(Request $request, CommandePressing $commande): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate(['etape' => ['required', Rule::in(array_keys(CommandePressing::ETAPES))]]);

        return response()->json(['data' => $this->json($this->commandes->etape($commande, $data['etape'], $request->user())->load('lignes', 'client', 'agent'))]);
    }

    /** Réglages du pressing : les conditions imprimées sur le reçu de dépôt. */
    public function reglages(): JsonResponse
    {
        return response()->json(['data' => ['conditions_depot' => $this->commandes->boutique()->conditions_depot]]);
    }

    public function majReglages(Request $request): JsonResponse
    {
        $boutique = $this->commandes->boutique();
        $data = $request->validate(['conditions_depot' => ['nullable', 'string', 'max:1000']]);
        $boutique->forceFill(['conditions_depot' => filled($data['conditions_depot'] ?? null) ? trim($data['conditions_depot']) : null])->save();

        return $this->reglages();
    }

    public function retrait(Request $request, CommandePressing $commande): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate([
            'moyen_paiement' => ['required', Rule::enum(MoyenPaiement::class)],
            'montant_donne' => ['nullable', 'integer', 'min:0'],
            'credit' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $this->json($this->commandes->retirer($commande, $data, $request->user())->load('lignes', 'client', 'agent'))]);
    }

    public function annuler(Request $request, CommandePressing $commande): JsonResponse
    {
        $this->commandes->boutique();
        $data = $request->validate(['motif' => ['nullable', 'string', 'max:255'], 'rembourser' => ['nullable', 'boolean']]);
        $commande = $this->commandes->annuler($commande, $data['motif'] ?? null, (bool) ($data['rembourser'] ?? false), $request->user());

        return response()->json(['data' => $this->json($commande->load('lignes', 'client', 'agent'))]);
    }

    /** @return array<string, mixed> */
    private function json(CommandePressing $c): array
    {
        return [
            'id' => $c->id,
            'numero' => $c->numero,
            'numero_lisible' => $c->numeroLisible(),
            'statut' => $c->statut,
            'etape' => $c->etape,
            'historique' => $c->historique ?? [],
            'rembourse' => (int) $c->encaissements()->where('type', 'remboursement')->sum('montant'),
            'en_retard' => $c->enRetard(),
            'express' => $c->express,
            'collecte' => $c->collecte,
            'livraison' => $c->livraison,
            'adresse' => $c->adresse,
            'casier' => $c->casier,
            'photos' => count($c->photos ?? []),
            'total' => $c->total,
            'acompte' => $c->acompte,
            'reste' => $c->reste(),
            'moyen_acompte' => $c->moyen_acompte,
            'retrait_prevu_le' => $c->retrait_prevu_le?->toIso8601String(),
            'prete_le' => $c->prete_le?->toIso8601String(),
            'retiree_le' => $c->retiree_le?->toIso8601String(),
            'annulee_le' => $c->annulee_le?->toIso8601String(),
            'motif_annulation' => $c->motif_annulation,
            'vente_id' => $c->vente_id,
            'notes' => $c->notes,
            'cree_le' => $c->created_at?->toIso8601String(),
            'servi_par' => $c->agent?->name,
            'client' => $c->client === null ? null : ['id' => $c->client->id, 'nom' => $c->client->nom, 'telephone' => $c->client->telephone],
            'lignes' => $c->lignes->map(fn ($l) => [
                'produit_id' => $l->produit_id, 'service_id' => $l->service_id, 'nom' => $l->nom, 'service' => $l->service,
                'quantite' => $l->quantite, 'prix_unitaire' => $l->prix_unitaire, 'total_ligne' => $l->total_ligne, 'defauts' => $l->defauts,
            ])->values(),
        ];
    }
}
