<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Country;
use App\Enums\MoyenPaiement;
use App\Models\Boutique;
use App\Models\Client;
use App\Models\CommandeFournisseur;
use App\Models\Fournisseur;
use App\Models\Plan;
use App\Models\Produit;
use App\Models\User;
use App\Support\Phone\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use App\Support\WhatsApp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Commandes aux grossistes.
 *
 * Le revendeur commande à son fournisseur. Si ce fournisseur tient une boutique
 * Ngoni (même numéro de téléphone), la commande arrive dans ses « Commandes
 * reçues » ; sinon, elle part sur WhatsApp. Le grossiste confirme (son article
 * et sa quantité pour chaque ligne), puis livre : une vente en gros naît dans sa
 * caisse, au client revendeur ; un achat chez le revendeur, qui fait monter son
 * stock et note sa dette envers le fournisseur.
 */
class CommandesFournisseur
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly VenteService $ventes,
        private readonly AchatService $achats,
        private readonly NotifierCompte $notifier,
    ) {}

    /** La boutique Ngoni du grossiste, reconnue à son numéro ; null s'il n'en a pas. */
    public function boutiqueDuFournisseur(Fournisseur $fournisseur): ?Boutique
    {
        if ($fournisseur->boutique_liee_id !== null) {
            return Boutique::withoutGlobalScopes()->find($fournisseur->boutique_liee_id);
        }
        if (blank($fournisseur->telephone)) {
            return null;
        }

        $pays = Country::tryFrom((string) Boutique::withoutGlobalScopes()->whereKey($fournisseur->boutique_id)->value('pays')) ?? Country::default();
        $telephone = PhoneNumber::normalize((string) $fournisseur->telephone, $pays);

        $boutique = Boutique::withoutGlobalScopes()
            ->whereKeyNot($fournisseur->boutique_id)
            ->where(fn ($q) => $q->where('telephone', $telephone)
                ->orWhereIn('proprietaire_id', User::where('phone', $telephone)->select('id')))
            ->oldest()
            ->first();

        if ($boutique !== null) {
            $fournisseur->forceFill(['boutique_liee_id' => $boutique->id])->save();
        }

        return $boutique;
    }

    /**
     * Le revendeur commande (boutique active : la sienne).
     *
     * @param  list<array{produit_id: string, quantite: float|int|string, prix_estime?: ?int}>  $lignes
     */
    public function commander(User $auteur, Fournisseur $fournisseur, array $lignes, ?string $note = null): CommandeFournisseur
    {
        $produits = Produit::whereIn('id', array_column($lignes, 'produit_id'))->get()->keyBy('id');
        $grossiste = $this->boutiqueDuFournisseur($fournisseur);

        $commande = DB::transaction(function () use ($auteur, $fournisseur, $lignes, $note, $produits, $grossiste): CommandeFournisseur {
            $commande = CommandeFournisseur::create([
                'fournisseur_id' => $fournisseur->id,
                'boutique_grossiste_id' => $grossiste?->id,
                'numero' => strtoupper(Str::random(6)),
                'statut' => 'envoyee',
                'note' => $note,
                'cree_par' => $auteur->id,
            ]);

            $total = 0;
            foreach ($lignes as $l) {
                $produit = $produits[$l['produit_id']] ?? throw ValidationException::withMessages(['lignes' => ['Article introuvable.']]);
                $prix = (int) ($l['prix_estime'] ?? $produit->prix_achat ?? 0);
                $quantite = (float) $l['quantite'];
                $commande->lignes()->create([
                    'produit_id' => $produit->id,
                    'nom' => $produit->nom,
                    'quantite' => $quantite,
                    'prix_estime' => $prix,
                    // Même article chez le grossiste (code-barres, sinon nom) : proposé d'avance.
                    'produit_grossiste_id' => $grossiste === null ? null : $this->articleCorrespondant($grossiste, $produit)?->id,
                ]);
                $total += (int) round($quantite * $prix);
            }
            $commande->update(['total_estime' => $total]);

            return $commande;
        });

        if ($grossiste?->proprietaire_id !== null && ($proprietaire = User::find($grossiste->proprietaire_id)) !== null) {
            $boutique = Boutique::withoutGlobalScopes()->find($commande->boutique_id);
            $this->notifier->envoyer($proprietaire, 'Nouvelle commande', "{$boutique?->nom} vous commande {$commande->lignes()->count()} article(s).", '/commandes-recues', 'commande', false);
        }

        return $commande->load('lignes');
    }

    /** Le message WhatsApp de la commande, pour un fournisseur qui n'a pas Ngoni. */
    public function lienWhatsApp(CommandeFournisseur $commande): ?string
    {
        $lien = WhatsApp::link($commande->fournisseur?->telephone);
        if ($lien === null) {
            return null;
        }
        $boutique = $commande->boutique?->nom;
        $texte = "Bonjour, commande n° {$commande->numero} de {$boutique} :\n"
            .$commande->lignes->map(fn ($l) => '• '.self::quantite($l->quantite).' × '.$l->nom)->implode("\n")
            .($commande->note ? "\n\n{$commande->note}" : '')
            ."\n\nMerci de me confirmer la livraison.";

        return $lien.'?text='.rawurlencode($texte);
    }

    /** Le revendeur annule tant que rien n'est livré. */
    public function annuler(CommandeFournisseur $commande): CommandeFournisseur
    {
        $this->exigerOuverte($commande);
        $commande->update(['statut' => 'annulee']);

        return $commande;
    }

    /**
     * Fournisseur sans Ngoni (commande partie sur WhatsApp) : le revendeur note
     * la marchandise arrivée ; l'achat se crée, au prix prévu ou corrigé.
     *
     * @param  list<array{id: int, quantite: float|int|string, prix_achat?: ?int}>|null  $lignes  null : tout est arrivé comme commandé
     */
    public function receptionner(CommandeFournisseur $commande, User $auteur, ?array $lignes = null, int $montantPaye = 0, string $moyen = 'especes'): CommandeFournisseur
    {
        if ($commande->boutique_grossiste_id !== null) {
            throw ValidationException::withMessages(['commande' => ['Votre fournisseur livre cette commande depuis son application.']]);
        }
        $this->exigerOuverte($commande);
        $parId = collect($lignes ?? [])->keyBy('id');
        $recues = $commande->lignes->map(fn ($l) => [
            'produit_id' => $l->produit_id,
            'quantite' => (float) ($lignes === null ? $l->quantite : ($parId[$l->id]['quantite'] ?? 0)),
            'prix_achat' => (int) ($parId[$l->id]['prix_achat'] ?? $l->prix_estime),
        ])->filter(fn ($l) => $l['quantite'] > 0)->values();
        if ($recues->isEmpty()) {
            throw ValidationException::withMessages(['lignes' => ['Aucun article reçu.']]);
        }

        return DB::transaction(function () use ($commande, $auteur, $recues, $montantPaye, $moyen): CommandeFournisseur {
            $achat = $this->achats->receptionner($auteur, $recues->all(), $commande->fournisseur_id, 'Commande '.$commande->numero, $montantPaye, $moyen);
            $commande->update(['statut' => 'livree', 'livree_le' => now(), 'achat_id' => $achat->id]);

            return $commande->load('lignes');
        });
    }

    /** Commandes reçues par le grossiste (boutique active : la sienne). @return Collection<int, CommandeFournisseur> */
    public function recues(?string $statut = null): Collection
    {
        return CommandeFournisseur::withoutBoutiqueScope()
            ->where('boutique_grossiste_id', $this->tenant->boutiqueId())
            ->when(filled($statut), fn ($q) => $q->where('statut', $statut))
            ->with(['lignes.produitGrossiste', 'boutique'])
            ->latest()->limit(100)->get();
    }

    /**
     * Le grossiste confirme : pour chaque ligne, son article et la quantité qu'il livre.
     *
     * @param  list<array{id: int, produit_grossiste_id: ?string, quantite: float|int|string}>  $lignes
     */
    public function confirmer(CommandeFournisseur $commande, array $lignes): CommandeFournisseur
    {
        $this->exigerDestinataire($commande);
        $this->exigerOuverte($commande);

        // Le prix annoncé est celui que la caisse appliquera à la livraison.
        $boutique = Boutique::withoutGlobalScopes()->findOrFail($commande->boutique_grossiste_id);
        $enGros = $boutique->venteEnGros() && app(AbonnementService::class)->permet($boutique, Plan::VENTE_GROS);

        DB::transaction(function () use ($commande, $lignes, $enGros): void {
            $parId = collect($lignes)->keyBy('id');
            foreach ($commande->lignes as $ligne) {
                $choix = $parId[$ligne->id] ?? null;
                $produit = filled($choix['produit_grossiste_id'] ?? null) ? Produit::find($choix['produit_grossiste_id']) : null;
                if (filled($choix['produit_grossiste_id'] ?? null) && $produit === null) {
                    throw ValidationException::withMessages(['lignes' => ['Article introuvable dans votre boutique.']]);
                }
                $ligne->update([
                    'produit_grossiste_id' => $produit?->id,
                    'quantite_confirmee' => $produit === null ? 0 : (float) ($choix['quantite'] ?? $ligne->quantite),
                    'prix_grossiste' => $produit === null ? null : (int) ($enGros && $produit->prix_gros !== null ? $produit->prix_gros : $produit->prix_vente),
                ]);
            }
            $commande->update(['statut' => 'confirmee', 'confirmee_le' => now()]);
        });

        $this->prevenirRevendeur($commande, 'Commande confirmée', 'confirme votre commande n° '.$commande->numero.'.');

        return $commande->load('lignes');
    }

    public function refuser(CommandeFournisseur $commande, string $motif): CommandeFournisseur
    {
        $this->exigerDestinataire($commande);
        $this->exigerOuverte($commande);
        $commande->update(['statut' => 'refusee', 'motif' => $motif]);
        $this->prevenirRevendeur($commande, 'Commande refusée', "ne peut pas livrer la commande n° {$commande->numero} : {$motif}");

        return $commande;
    }

    /**
     * Le grossiste livre : vente en gros dans sa caisse (au client revendeur,
     * à crédit pour ce qui n'est pas payé), achat chez le revendeur (son stock
     * monte, sa dette au fournisseur est notée).
     */
    public function livrer(CommandeFournisseur $commande, User $grossiste, int $montantPaye = 0, string $moyen = 'especes'): CommandeFournisseur
    {
        $this->exigerDestinataire($commande);
        if ($commande->statut !== 'confirmee') {
            throw ValidationException::withMessages(['commande' => ['Confirmez la commande avant de la livrer.']]);
        }
        $aLivrer = $commande->lignes->filter(fn ($l) => $l->produit_grossiste_id !== null && $l->quantite_confirmee > 0)->values();
        if ($aLivrer->isEmpty()) {
            throw ValidationException::withMessages(['commande' => ['Aucun article à livrer.']]);
        }

        $grossisteId = (string) $this->tenant->boutiqueId();

        return DB::transaction(function () use ($commande, $grossiste, $montantPaye, $moyen, $aLivrer, $grossisteId): CommandeFournisseur {
            $client = $this->clientRevendeur($commande);
            $vente = $this->ventes->encaisser([
                'client_id' => $client->id,
                'lignes' => $aLivrer->map(fn ($l) => ['produit_id' => $l->produit_grossiste_id, 'quantite' => $l->quantite_confirmee])->all(),
                'moyen_paiement' => $montantPaye > 0 ? $moyen : MoyenPaiement::CreditClient->value,
                'montant_paye' => $montantPaye,
            ], $grossiste);

            // Chez le revendeur : l'achat, au prix réellement facturé.
            $prix = $vente->lignes->keyBy('produit_id');
            $paye = (int) $vente->montant_paye;
            $this->tenant->setBoutique($commande->boutique_id);
            try {
                $achat = $this->achats->receptionner(
                    User::findOrFail($commande->cree_par),
                    $aLivrer->map(fn ($l) => [
                        'produit_id' => $l->produit_id,
                        'quantite' => $l->quantite_confirmee,
                        'prix_achat' => (int) ($prix[$l->produit_grossiste_id]?->prix_unitaire ?? $l->prix_grossiste ?? 0),
                    ])->all(),
                    $commande->fournisseur_id,
                    'Commande '.$commande->numero,
                    min($paye, (int) $vente->total),
                    $moyen,
                );
            } finally {
                $this->tenant->setBoutique($grossisteId);
            }

            $commande->update(['statut' => 'livree', 'livree_le' => now(), 'vente_id' => $vente->id, 'achat_id' => $achat->id]);
            $this->prevenirRevendeur($commande, 'Commande livrée', "a livré votre commande n° {$commande->numero} : votre stock est à jour.");

            return $commande->load('lignes');
        });
    }

    /** Le client « revendeur » du grossiste pour cette boutique : retrouvé par téléphone, sinon créé. */
    private function clientRevendeur(CommandeFournisseur $commande): Client
    {
        $revendeur = $commande->boutique;
        $telephone = $revendeur?->telephone ?: User::whereKey($revendeur?->proprietaire_id)->value('phone');

        $client = $telephone === null ? null : Client::where('telephone', $telephone)->first();
        if ($client === null) {
            $client = Client::create(['nom' => (string) $revendeur?->nom, 'telephone' => $telephone, 'revendeur' => true]);
        } elseif (! $client->revendeur) {
            $client->update(['revendeur' => true]);
        }

        return $client;
    }

    private function articleCorrespondant(Boutique $grossiste, Produit $produit): ?Produit
    {
        $chez = Produit::withoutGlobalScopes()->where('boutique_id', $grossiste->id)->where('actif', true);

        return (filled($produit->code_barre) ? (clone $chez)->where('code_barre', $produit->code_barre)->first() : null)
            ?? (clone $chez)->whereRaw('LOWER(nom) = ?', [mb_strtolower($produit->nom)])->first();
    }

    private function prevenirRevendeur(CommandeFournisseur $commande, string $titre, string $suite): void
    {
        $auteur = User::find($commande->cree_par);
        if ($auteur !== null) {
            $grossiste = $commande->boutiqueGrossiste?->nom ?? 'Votre fournisseur';
            $this->notifier->envoyer($auteur, $titre, "{$grossiste} {$suite}", '/achats', 'commande', false);
        }
    }

    private function exigerOuverte(CommandeFournisseur $commande): void
    {
        if (! $commande->ouverte()) {
            throw ValidationException::withMessages(['commande' => ['Cette commande est déjà '.mb_strtolower(CommandeFournisseur::STATUTS[$commande->statut] ?? $commande->statut).'.']]);
        }
    }

    private function exigerDestinataire(CommandeFournisseur $commande): void
    {
        abort_unless($commande->boutique_grossiste_id !== null && $commande->boutique_grossiste_id === $this->tenant->boutiqueId(), 404);
    }

    private static function quantite(float $q): string
    {
        return rtrim(rtrim(number_format($q, 3, ',', ' '), '0'), ',');
    }
}
