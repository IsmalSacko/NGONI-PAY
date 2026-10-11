<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Country;
use App\Enums\MoyenPaiement;
use App\Models\Boutique;
use App\Models\Client;
use App\Models\CommandeFournisseur;
use App\Models\Fournisseur;
use App\Models\PaiementFournisseur;
use App\Models\Plan;
use App\Models\Produit;
use App\Models\ReglementCredit;
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
        private readonly ReglementsCredit $reglements,
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
     * Depuis le catalogue du grossiste, une ligne dit aussi son article
     * (produit_grossiste_id) et combien d'unités du revendeur il contient :
     * c'est retenu pour la suite.
     *
     * @param  list<array{produit_id: string, quantite: float|int|string, unite_commande?: ?string, prix_estime?: ?int, produit_grossiste_id?: ?string, contenance?: float|int|string|null}>  $lignes  quantité : dans l'unité de base du revendeur ; unite_commande : son palier (carton), pour l'affichage
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
                // Ce que le grossiste a déjà dit pour cet article (« 1 carton =
                // 24 sachets »), sinon le même article chez lui (code-barres, nom).
                $connue = $grossiste === null ? null : DB::table('correspondances_produits')
                    ->where('boutique_grossiste_id', $grossiste->id)->where('produit_revendeur_id', $produit->id)->first();
                if ($grossiste !== null && filled($l['produit_grossiste_id'] ?? null)) {
                    $article = Produit::withoutGlobalScopes()->where('boutique_id', $grossiste->id)->find($l['produit_grossiste_id'])
                        ?? throw ValidationException::withMessages(['lignes' => ['Article introuvable chez ce fournisseur.']]);
                    $connue = (object) ['produit_grossiste_id' => $article->id, 'contenance' => max(0.001, (float) ($l['contenance'] ?? 1))];
                    $this->retenir($grossiste->id, $produit->id, $article->id, $connue->contenance);
                }
                $article = $connue === null && $grossiste !== null ? $this->articleCorrespondant($grossiste, $produit) : null;
                $palier = filled($l['unite_commande'] ?? null) ? $produit->palier($l['unite_commande']) : null;
                $commande->lignes()->create([
                    'produit_id' => $produit->id,
                    'nom' => $produit->nom,
                    'unite' => $produit->unite,
                    'unite_commande' => $palier !== null && $palier['contenance'] > 1 ? $palier['unite'] : null,
                    'contenance_commande' => $palier !== null && $palier['contenance'] > 1 ? $palier['contenance'] : 1,
                    'quantite' => $quantite,
                    'prix_estime' => $prix,
                    'produit_grossiste_id' => $connue->produit_grossiste_id ?? $article?->id,
                    'contenance' => (float) ($connue->contenance ?? ($article === null ? 1 : self::contenanceProposee($produit, $article))),
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
            .$commande->lignes->map(fn ($l) => '• '.self::quantite($l->quantiteCommandee()).(($l->unite_commande ?? $l->unite) ? ' '.($l->unite_commande ?? $l->unite) : '').' × '.$l->nom)->implode("\n")
            .($commande->note ? "\n\n{$commande->note}" : '')
            ."\n\nMerci de me confirmer la livraison."
            // Le fournisseur qui rejoint Ngoni reçoit les commandes suivantes dans son application.
            ."\n\nRecevez mes prochaines commandes directement dans Ngoni Caisse, avec votre numéro : https://ngonipay.ismael-dev.com/app";

        return $lien.'?text='.rawurlencode($texte);
    }

    /**
     * Le catalogue du grossiste, vu par le revendeur : ses articles, au prix
     * qu'il lui facturera, et l'article du revendeur qui leur correspond.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(Fournisseur $fournisseur, ?string $recherche = null): array
    {
        $grossiste = $this->boutiqueDuFournisseur($fournisseur);
        if ($grossiste === null) {
            return [];
        }
        $enGros = $this->vendEnGros($grossiste);
        $produits = Produit::withoutGlobalScopes()->where('boutique_id', $grossiste->id)->where('actif', true)
            ->when(filled($recherche), fn ($q) => $q->where('nom', 'like', '%'.str_replace(['%', '_'], '', (string) $recherche).'%'))
            ->orderBy('nom')->limit(300)->get();
        $correspondances = DB::table('correspondances_produits')->where('boutique_grossiste_id', $grossiste->id)
            ->whereIn('produit_revendeur_id', Produit::query()->select('id'))
            ->get()->keyBy('produit_grossiste_id');
        // Mes articles : ceux déjà liés, et de quoi reconnaître les autres
        // (même code-barres, sinon même nom), la conversion tirée de mes paliers.
        $miens = Produit::query()->get(['id', 'nom', 'unite', 'code_barre', 'paliers']);
        $parId = $miens->keyBy('id');
        $parCode = $miens->filter(fn ($m) => filled($m->code_barre))->keyBy('code_barre');
        $parNom = $miens->keyBy(fn ($m) => mb_strtolower(trim($m->nom)));

        $suitStock = $grossiste->suitLeStock();

        return $produits->map(function (Produit $p) use ($enGros, $correspondances, $parId, $parCode, $parNom, $suitStock) {
            $c = $correspondances[$p->id] ?? null;
            $mien = $c === null ? null : ($parId[$c->produit_revendeur_id] ?? null);
            if ($mien === null) {
                $mien = (filled($p->code_barre) ? ($parCode[$p->code_barre] ?? null) : null) ?? ($parNom[mb_strtolower(trim($p->nom))] ?? null);
                $c = $mien === null ? null : (object) ['contenance' => self::contenanceProposee($mien, $p)];
            }

            return [
                'id' => $p->id,
                'nom' => $p->nom,
                'unite' => $p->unite,
                'prix' => (int) ($enGros && $p->prix_gros !== null ? $p->prix_gros : $p->prix_vente),
                'disponible' => ! $suitStock || $p->stock > 0,
                'vignette_url' => $p->vignette_url,
                'chez_moi' => $mien === null ? null : [
                    'produit_id' => $mien->id, 'nom' => $mien->nom, 'unite' => $mien->unite, 'contenance' => (float) $c->contenance,
                    // Mon palier du même nom que son unité (carton) : la commande s'y lit.
                    'unite_commande' => (float) $c->contenance > 1 ? $p->unite : null,
                ],
            ];
        })->values()->all();
    }

    /** « Commander à nouveau » : les mêmes articles, aux mêmes quantités. */
    public function recommander(CommandeFournisseur $commande, User $auteur): CommandeFournisseur
    {
        $fournisseur = Fournisseur::findOrFail($commande->fournisseur_id);

        return $this->commander($auteur, $fournisseur, $commande->lignes->map(fn ($l) => [
            'produit_id' => $l->produit_id,
            'quantite' => $l->quantite,
        ])->all(), $commande->note);
    }

    /** Le grossiste ouvre la commande : le revendeur voit « Vue ». */
    public function marquerVue(CommandeFournisseur $commande): void
    {
        $this->exigerDestinataire($commande);
        if ($commande->vue_le === null) {
            $commande->update(['vue_le' => now()]);
        }
    }

    /**
     * Combien d'unités de base du revendeur dans une unité du grossiste, tirées
     * de ce que le revendeur a déjà renseigné : même unité (1), ou son palier
     * du même nom (son « carton » de 24 L pour le « carton » du grossiste).
     */
    public static function contenanceProposee(Produit $mien, Produit $sien): float
    {
        $unite = self::uniteNormalisee($sien->unite);
        if ($unite === null || $unite === self::uniteNormalisee($mien->unite)) {
            return 1;
        }
        foreach ($mien->paliers ?? [] as $p) {
            if (self::uniteNormalisee($p['unite'] ?? null) === $unite) {
                return (float) $p['contenance'];
            }
        }

        return 1;
    }

    private static function uniteNormalisee(?string $unite): ?string
    {
        $u = rtrim(mb_strtolower(trim((string) $unite)), 's');

        return $u === '' ? null : $u;
    }

    private function retenir(string $grossisteId, string $produitRevendeurId, string $produitGrossisteId, float $contenance): void
    {
        DB::table('correspondances_produits')->updateOrInsert(
            ['produit_revendeur_id' => $produitRevendeurId, 'boutique_grossiste_id' => $grossisteId],
            ['produit_grossiste_id' => $produitGrossisteId, 'contenance' => $contenance, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    private function vendEnGros(Boutique $boutique): bool
    {
        return $boutique->venteEnGros() && app(AbonnementService::class)->permet($boutique, Plan::VENTE_GROS);
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
            ->with(['lignes.produitGrossiste', 'lignes.produit', 'boutique'])
            ->latest()->limit(100)->get();
    }

    /**
     * Le grossiste confirme : pour chaque ligne, son article et la quantité qu'il livre.
     *
     * @param  list<array{id: int, produit_grossiste_id: ?string, quantite: float|int|string, contenance?: float|int|string|null}>  $lignes  quantité : dans l'unité du grossiste (cartons) ; contenance : unités du revendeur dans l'une d'elles
     */
    public function confirmer(CommandeFournisseur $commande, array $lignes): CommandeFournisseur
    {
        $this->exigerDestinataire($commande);
        $this->exigerOuverte($commande);

        // Le prix annoncé est celui que la caisse appliquera à la livraison.
        $boutique = Boutique::withoutGlobalScopes()->findOrFail($commande->boutique_grossiste_id);
        $enGros = $this->vendEnGros($boutique);

        DB::transaction(function () use ($commande, $lignes, $enGros): void {
            $parId = collect($lignes)->keyBy('id');
            foreach ($commande->lignes as $ligne) {
                $choix = $parId[$ligne->id] ?? null;
                $produit = filled($choix['produit_grossiste_id'] ?? null) ? Produit::find($choix['produit_grossiste_id']) : null;
                if (filled($choix['produit_grossiste_id'] ?? null) && $produit === null) {
                    throw ValidationException::withMessages(['lignes' => ['Article introuvable dans votre boutique.']]);
                }
                $proposee = $produit !== null && $produit->id !== $ligne->produit_grossiste_id && ($mien = $ligne->produit) !== null
                    ? self::contenanceProposee($mien, $produit) : ($ligne->contenance ?? 1);
                $contenance = max(0.001, (float) ($choix['contenance'] ?? $proposee));
                $ligne->update([
                    'produit_grossiste_id' => $produit?->id,
                    'contenance' => $contenance,
                    'quantite_confirmee' => $produit === null ? 0 : (float) ($choix['quantite'] ?? ceil($ligne->quantite / $contenance)),
                    'prix_grossiste' => $produit === null ? null : (int) ($enGros && $produit->prix_gros !== null ? $produit->prix_gros : $produit->prix_vente),
                ]);
            }
            // Retenu pour les prochaines commandes de cet article.
            foreach ($commande->lignes()->whereNotNull('produit_grossiste_id')->get() as $ligne) {
                $this->retenir($commande->boutique_grossiste_id, $ligne->produit_id, $ligne->produit_grossiste_id, $ligne->contenance);
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
                    // 2 cartons à 7 200 F = 48 sachets à 300 F chez le revendeur.
                    $aLivrer->map(fn ($l) => [
                        'produit_id' => $l->produit_id,
                        'quantite' => round($l->quantite_confirmee * $l->contenance, 3),
                        'prix_achat' => (int) round(($prix[$l->produit_grossiste_id]?->prix_unitaire ?? $l->prix_grossiste ?? 0) / max(0.001, $l->contenance)),
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

    /**
     * Le revendeur a payé un fournisseur qui a Ngoni, et dont il est client :
     * le paiement est déclaré au grossiste, qui le confirme (son crédit baisse)
     * ou le refuse. Rien ne se déduit sans lui.
     */
    public function declarerPaiement(PaiementFournisseur $paiement, Fournisseur $fournisseur): void
    {
        $grossiste = $this->boutiqueDuFournisseur($fournisseur);
        $revendeur = Boutique::withoutGlobalScopes()->find($paiement->boutique_id);
        if ($grossiste === null || $revendeur === null) {
            return;
        }
        $client = $this->clientChez($grossiste, $revendeur);
        if ($client === null) {
            return;
        }
        $paiement->forceFill(['boutique_grossiste_id' => $grossiste->id, 'client_grossiste_id' => $client->id, 'statut_grossiste' => 'a_confirmer'])->save();

        if (($proprietaire = User::find($grossiste->proprietaire_id)) !== null) {
            $moyen = MoyenPaiement::tryFrom($paiement->moyen_paiement)?->label() ?? $paiement->moyen_paiement;
            $this->notifier->envoyer(
                $proprietaire,
                'Paiement à confirmer',
                "{$revendeur->nom} dit vous avoir payé ".self::montant((int) $paiement->montant)." ({$moyen}). Confirmez la réception pour déduire son crédit.",
                '/clients?client='.$client->id,
                'commande',
                false,
            );
        }
    }

    /** Paiements déclarés par ce client, en attente (boutique active : le grossiste). @return Collection<int, PaiementFournisseur> */
    public function paiementsDeclares(Client $client): Collection
    {
        return PaiementFournisseur::withoutBoutiqueScope()
            ->where('boutique_grossiste_id', $this->tenant->boutiqueId())
            ->where('client_grossiste_id', $client->id)
            ->where('statut_grossiste', 'a_confirmer')
            ->with('boutique')->latest()->get();
    }

    /** Le grossiste a bien reçu l'argent : remboursement du crédit, reçu à la clé. */
    public function confirmerPaiement(string $id, User $caissier): ReglementCredit
    {
        $paiement = $this->paiementDeclare($id);
        $client = Client::findOrFail($paiement->client_grossiste_id);
        $montant = min((int) $paiement->montant, $client->soldeDu());
        if ($montant <= 0) {
            throw ValidationException::withMessages(['paiement' => ['Ce client ne vous doit plus rien.']]);
        }
        $moyen = MoyenPaiement::tryFrom($paiement->moyen_paiement) !== null && $paiement->moyen_paiement !== MoyenPaiement::CreditClient->value
            ? $paiement->moyen_paiement : MoyenPaiement::Especes->value;

        $reglement = DB::transaction(function () use ($paiement, $client, $caissier, $montant, $moyen): ReglementCredit {
            $reglement = $this->reglements->enregistrer($client, $caissier, $montant, $moyen, 'Paiement déclaré par '.$paiement->boutique?->nom);
            $paiement->forceFill(['statut_grossiste' => 'confirme', 'reglement_credit_id' => $reglement->id])->save();

            return $reglement;
        });
        $this->prevenirPayeur($paiement, 'Paiement confirmé', 'a bien reçu votre paiement de '.self::montant($montant).'.');

        return $reglement;
    }

    public function refuserPaiement(string $id): void
    {
        $paiement = $this->paiementDeclare($id);
        $paiement->forceFill(['statut_grossiste' => 'refuse'])->save();
        $this->prevenirPayeur($paiement, 'Paiement non reçu', 'n’a pas reçu votre paiement de '.self::montant((int) $paiement->montant).'. Voyez avec lui.');
    }

    private function paiementDeclare(string $id): PaiementFournisseur
    {
        $paiement = PaiementFournisseur::withoutBoutiqueScope()->with('boutique')
            ->where('boutique_grossiste_id', $this->tenant->boutiqueId())->findOrFail($id);
        if ($paiement->statut_grossiste !== 'a_confirmer') {
            throw ValidationException::withMessages(['paiement' => ['Ce paiement est déjà traité.']]);
        }

        return $paiement;
    }

    private function prevenirPayeur(PaiementFournisseur $paiement, string $titre, string $suite): void
    {
        if (($payeur = User::find($paiement->user_id)) !== null) {
            $grossiste = Boutique::withoutGlobalScopes()->whereKey($paiement->boutique_grossiste_id)->value('nom') ?? 'Votre fournisseur';
            $this->notifier->envoyer($payeur, $titre, "{$grossiste} {$suite}", '/achats', 'commande', false);
        }
    }

    /** Le client qui représente [revendeur] chez [grossiste] (par téléphone), s'il existe. */
    private function clientChez(Boutique $grossiste, Boutique $revendeur): ?Client
    {
        $telephones = array_values(array_filter([$revendeur->telephone, User::whereKey($revendeur->proprietaire_id)->value('phone')]));

        return $telephones === [] ? null : Client::withoutGlobalScopes()
            ->where('boutique_id', $grossiste->id)->whereIn('telephone', $telephones)->oldest()->first();
    }

    private static function montant(int $f): string
    {
        return number_format($f, 0, ',', ' ').' F';
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
