<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        ]);

        return response()->json($this->recue($this->commandes->confirmer($this->trouverRecue($commande), $data['lignes'])));
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
        $c->loadMissing(['lignes.produitGrossiste', 'boutique']);

        return [
            ...$this->commun($c),
            'client' => $c->boutique?->only(['nom', 'telephone']),
            'lignes' => $c->lignes->map(fn ($l) => [
                ...$this->ligne($l),
                'produit_grossiste' => $l->produitGrossiste?->only(['id', 'nom', 'stock', 'prix_vente', 'prix_gros']),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function commun(CommandeFournisseur $c): array
    {
        $c->loadMissing('lignes');

        return [
            'id' => $c->id, 'numero' => $c->numero, 'statut' => $c->statut,
            'statut_libelle' => CommandeFournisseur::STATUTS[$c->statut] ?? $c->statut,
            'note' => $c->note, 'motif' => $c->motif, 'total_estime' => (int) $c->total_estime,
            'total_confirme' => (int) $c->lignes->sum(fn ($l) => round((float) $l->quantite_confirmee * (int) $l->prix_grossiste)),
            'cree_le' => $c->created_at?->toIso8601String(), 'confirmee_le' => $c->confirmee_le?->toIso8601String(), 'livree_le' => $c->livree_le?->toIso8601String(),
            'vente_id' => $c->vente_id, 'achat_id' => $c->achat_id,
            'lignes' => $c->lignes->map(fn ($l) => $this->ligne($l)),
        ];
    }

    /** @return array<string, mixed> */
    private function ligne($l): array
    {
        return [
            'id' => $l->id, 'produit_id' => $l->produit_id, 'nom' => $l->nom,
            'quantite' => (float) $l->quantite, 'prix_estime' => (int) $l->prix_estime,
            'produit_grossiste_id' => $l->produit_grossiste_id,
            'quantite_confirmee' => $l->quantite_confirmee === null ? null : (float) $l->quantite_confirmee,
            'prix_grossiste' => $l->prix_grossiste === null ? null : (int) $l->prix_grossiste,
        ];
    }
}
