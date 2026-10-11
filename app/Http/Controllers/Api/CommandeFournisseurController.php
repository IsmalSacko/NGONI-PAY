<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CommandeFournisseur;
use App\Models\Fournisseur;
use App\Services\CommandesFournisseur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Commandes aux grossistes : envoyées par le revendeur, reçues par le grossiste. */
class CommandeFournisseurController extends Controller
{
    private const MOYENS = ['especes', 'orange_money', 'moov_money', 'wave', 'carte', 'virement'];

    public function __construct(private readonly CommandesFournisseur $commandes) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => CommandeFournisseur::with(['lignes', 'fournisseur:id,nom,telephone', 'boutiqueGrossiste:id,nom'])
            ->latest()->limit(100)->get()->map(fn (CommandeFournisseur $c) => $this->envoyee($c))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fournisseur_id' => ['required', 'uuid'],
            'lignes' => ['required', 'array', 'min:1', 'max:200'],
            'lignes.*.produit_id' => ['required', 'uuid'],
            'lignes.*.quantite' => ['required', 'numeric', 'gt:0'],
            'lignes.*.prix_estime' => ['nullable', 'integer', 'min:0'],
            'lignes.*.produit_grossiste_id' => ['nullable', 'uuid'],
            'lignes.*.contenance' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'lignes.*.unite_commande' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $fournisseur = Fournisseur::findOrFail($data['fournisseur_id']);
        $commande = $this->commandes->commander($request->user(), $fournisseur, $data['lignes'], $data['note'] ?? null);

        return response()->json($this->envoyee($commande->load(['fournisseur', 'boutiqueGrossiste'])), 201);
    }

    /** Le fournisseur a-t-il Ngoni ? (pour dire d'avance « arrivera dans son application » ou « WhatsApp »). */
    public function fournisseur(Fournisseur $fournisseur): JsonResponse
    {
        $boutique = $this->commandes->boutiqueDuFournisseur($fournisseur);

        return response()->json(['sur_ngoni' => $boutique !== null, 'boutique' => $boutique?->nom]);
    }

    /** Le catalogue du fournisseur, s'il a Ngoni (vide sinon). */
    public function catalogue(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        return response()->json(['data' => $this->commandes->catalogue($fournisseur, $request->query('q'))]);
    }

    public function recommander(Request $request, CommandeFournisseur $commande): JsonResponse
    {
        $nouvelle = $this->commandes->recommander($commande->load('lignes'), $request->user());

        return response()->json($this->envoyee($nouvelle->load(['fournisseur', 'boutiqueGrossiste'])), 201);
    }

    public function vue(string $commande): JsonResponse
    {
        $this->commandes->marquerVue($this->trouverRecue($commande));

        return response()->json(['message' => 'Vue.']);
    }

    public function annuler(CommandeFournisseur $commande): JsonResponse
    {
        return response()->json($this->envoyee($this->commandes->annuler($commande)->load(['lignes', 'fournisseur', 'boutiqueGrossiste'])));
    }

    public function receptionner(Request $request, CommandeFournisseur $commande): JsonResponse
    {
        $data = $request->validate([
            'lignes' => ['nullable', 'array'],
            'lignes.*.id' => ['required', 'integer'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0'],
            'lignes.*.prix_achat' => ['nullable', 'integer', 'min:0'],
            'montant_paye' => ['nullable', 'integer', 'min:0'],
            'moyen_paiement' => ['nullable', Rule::in(self::MOYENS)],
        ]);
        $commande = $this->commandes->receptionner($commande, $request->user(), $data['lignes'] ?? null, (int) ($data['montant_paye'] ?? 0), $data['moyen_paiement'] ?? 'especes');

        return response()->json($this->envoyee($commande->load(['fournisseur', 'boutiqueGrossiste'])));
    }

    public function recues(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->commandes->recues($request->query('statut'))->map(fn (CommandeFournisseur $c) => $this->recue($c))]);
    }

    public function confirmer(Request $request, string $commande): JsonResponse
    {
        $data = $request->validate([
            'lignes' => ['required', 'array'],
            'lignes.*.id' => ['required', 'integer'],
            'lignes.*.produit_grossiste_id' => ['nullable', 'uuid'],
            'lignes.*.quantite' => ['nullable', 'numeric', 'min:0'],
            'lignes.*.contenance' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
        ]);

        return response()->json($this->recue($this->commandes->confirmer($this->trouverRecue($commande), $data['lignes'])));
    }

    public function expedier(string $commande): JsonResponse
    {
        return response()->json($this->recue($this->commandes->expedier($this->trouverRecue($commande))));
    }

    public function refuser(Request $request, string $commande): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:300']]);

        return response()->json($this->recue($this->commandes->refuser($this->trouverRecue($commande), $data['motif'])));
    }

    public function livrer(Request $request, string $commande): JsonResponse
    {
        $data = $request->validate([
            'montant_paye' => ['nullable', 'integer', 'min:0'],
            'moyen_paiement' => ['nullable', Rule::in(self::MOYENS)],
        ]);
        $livree = $this->commandes->livrer($this->trouverRecue($commande), $request->user(), (int) ($data['montant_paye'] ?? 0), $data['moyen_paiement'] ?? 'especes');

        return response()->json($this->recue($livree));
    }

    /** Grossiste : les paiements que ce client dit lui avoir faits, à confirmer. */
    public function paiementsDeclares(Client $client): JsonResponse
    {
        return response()->json(['data' => $this->commandes->paiementsDeclares($client)->map(fn ($p) => [
            'id' => $p->id, 'montant' => (int) $p->montant, 'moyen_paiement' => $p->moyen_paiement,
            'note' => $p->note, 'boutique' => $p->boutique?->nom, 'cree_le' => $p->created_at?->toIso8601String(),
        ])]);
    }

    public function confirmerPaiement(Request $request, string $paiement): JsonResponse
    {
        $reglement = $this->commandes->confirmerPaiement($paiement, $request->user());
        $client = $reglement->client;

        return response()->json(['solde_du' => $client->soldeDu(), 'recu' => $reglement->load(['caissier', 'client'])->recu()]);
    }

    public function refuserPaiement(string $paiement): JsonResponse
    {
        $this->commandes->refuserPaiement($paiement);

        return response()->json(['message' => 'Paiement refusé : le revendeur est prévenu.']);
    }

    private function trouverRecue(string $id): CommandeFournisseur
    {
        return CommandeFournisseur::withoutBoutiqueScope()->with(['lignes', 'boutique'])->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function envoyee(CommandeFournisseur $c): array
    {
        return [
            ...$this->commun($c),
            'fournisseur' => $c->fournisseur?->only(['id', 'nom', 'telephone']),
            'grossiste' => $c->boutiqueGrossiste?->nom,
            'whatsapp' => $c->boutique_grossiste_id === null && $c->ouverte() ? $this->commandes->lienWhatsApp($c) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function recue(CommandeFournisseur $c): array
    {
        $c->loadMissing(['lignes.produitGrossiste', 'lignes.produit', 'boutique']);

        return [
            ...$this->commun($c),
            // Vu du grossiste : il ne l'a pas « envoyée », il l'a reçue et la traite.
            'statut_libelle' => match ($c->statut) {
                'envoyee' => 'Nouvelle',
                'confirmee' => 'À livrer',
                'en_livraison' => 'En livraison',
                'annulee' => 'Annulée par le client',
                default => CommandeFournisseur::STATUTS[$c->statut] ?? $c->statut,
            },
            'client' => $c->boutique?->only(['nom', 'telephone']),
            'lignes' => $c->lignes->map(fn ($l) => [
                ...$this->ligne($l),
                'produit_grossiste' => $l->produitGrossiste?->only(['id', 'nom', 'unite', 'stock', 'prix_vente', 'prix_gros']),
                // Ce qu'il doit livrer, dans ses unités : 2 (cartons), pas 48 (L).
                'quantite_grossiste' => round((float) $l->quantite / max(0.001, (float) $l->contenance), 3),
                // Ses paliers : le grossiste qui choisit son « carton » voit la conversion se faire seule.
                'paliers_revendeur' => collect($l->produit?->paliers ?? [])->map(fn ($p) => ['unite' => $p['unite'] ?? null, 'contenance' => (float) ($p['contenance'] ?? 1)])->values(),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function commun(CommandeFournisseur $c): array
    {
        $c->loadMissing('lignes');

        return [
            'id' => $c->id, 'numero' => $c->numero, 'statut' => $c->statut,
            'statut_libelle' => $c->statut === 'envoyee' && $c->vue_le !== null ? 'Vue' : (CommandeFournisseur::STATUTS[$c->statut] ?? $c->statut),
            'vue_le' => $c->vue_le?->toIso8601String(),
            'note' => $c->note, 'motif' => $c->motif, 'total_estime' => (int) $c->total_estime,
            'total_confirme' => (int) $c->lignes->sum(fn ($l) => round((float) $l->quantite_confirmee * (int) $l->prix_grossiste)),
            'cree_le' => $c->created_at?->toIso8601String(), 'confirmee_le' => $c->confirmee_le?->toIso8601String(), 'expediee_le' => $c->expediee_le?->toIso8601String(), 'livree_le' => $c->livree_le?->toIso8601String(),
            'vente_id' => $c->vente_id, 'achat_id' => $c->achat_id,
            'lignes' => $c->lignes->map(fn ($l) => $this->ligne($l)),
        ];
    }

    /** @return array<string, mixed> */
    private function ligne($l): array
    {
        return [
            'id' => $l->id, 'produit_id' => $l->produit_id, 'nom' => $l->nom, 'unite' => $l->unite, 'contenance' => (float) $l->contenance,
            'unite_commande' => $l->unite_commande, 'contenance_commande' => (float) $l->contenance_commande,
            'quantite' => (float) $l->quantite, 'prix_estime' => (int) $l->prix_estime,
            'produit_grossiste_id' => $l->produit_grossiste_id,
            'quantite_confirmee' => $l->quantite_confirmee === null ? null : (float) $l->quantite_confirmee,
            'prix_grossiste' => $l->prix_grossiste === null ? null : (int) $l->prix_grossiste,
        ];
    }
}
