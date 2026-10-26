<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Country;
use App\Enums\MoyenPaiement;
use App\Models\Boutique;
use App\Models\Client;
use App\Models\CommandeFournisseur;
use App\Models\Fournisseur;
use App\Models\LiaisonGrossiste;
use App\Models\PaiementFournisseur;
use App\Models\Plan;
use App\Models\Produit;
use App\Models\ReglementCredit;
use App\Models\UniteBoutique;
use App\Models\User;
use App\Support\Phone\PhoneNumber;
use App\Support\Quantite;
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
        $boutique = $this->boutiqueLiee($fournisseur);
        // Il ne reçoit pas de commandes, ou a refusé ce revendeur : WhatsApp, comme sans Ngoni.
        if ($boutique === null || ! $boutique->recoit_commandes
            || $this->statutLiaison($fournisseur->boutique_id, $boutique->id) === 'refusee') {
            return null;
        }

        return $boutique;
    }

    private function boutiqueLiee(Fournisseur $fournisseur): ?Boutique
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
        $grossiste = $this->boutiqueDuFournisseur($fournisseur);

        $commande = DB::transaction(function () use ($auteur, $fournisseur, $lignes, $note, $grossiste): CommandeFournisseur {
            // Un article de son catalogue que je ne vends pas encore : créé chez
            // moi (son nom, son unité, son prix), la ligne le désigne ensuite.
            foreach ($lignes as $i => $l) {
                if (blank($l['produit_id'] ?? null)) {
                    $lignes[$i]['produit_id'] = $this->creerDepuisCatalogue($grossiste, (string) ($l['produit_grossiste_id'] ?? ''))->id;
                    $lignes[$i]['contenance'] = 1;
                }
            }
            $produits = Produit::whereIn('id', array_column($lignes, 'produit_id'))->get()->keyBy('id');

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
        if ($grossiste !== null) {
            $this->liaison($commande->boutique_id, $grossiste->id);
        }

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
        // Ses prix ne se montrent qu'aux revendeurs qu'il a acceptés.
        if ($grossiste === null || $this->statutLiaison($fournisseur->boutique_id, $grossiste->id) !== 'acceptee') {
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
        $miens = Produit::query()->get(['id', 'nom', 'unite', 'code_barre', 'paliers', 'prix_vente']);
        $parId = $miens->keyBy('id');
        $parCode = $miens->filter(fn ($m) => filled($m->code_barre))->keyBy(fn ($m) => Produit::cleCodeBarre($m->code_barre));
        $parNom = $miens->keyBy(fn ($m) => mb_strtolower(trim($m->nom)));

        $suitStock = $grossiste->suitLeStock();

        return $produits->map(function (Produit $p) use ($enGros, $correspondances, $parId, $parCode, $parNom, $suitStock) {
            $c = $correspondances[$p->id] ?? null;
            $mien = $c === null ? null : ($parId[$c->produit_revendeur_id] ?? null);
            if ($mien === null) {
                $mien = (filled($p->code_barre) ? ($parCode[Produit::cleCodeBarre($p->code_barre)] ?? null) : null) ?? ($parNom[mb_strtolower(trim($p->nom))] ?? null);
                $c = $mien === null ? null : (object) ['contenance' => self::contenanceProposee($mien, $p, $enGros && $p->prix_gros !== null ? (int) $p->prix_gros : (int) $p->prix_vente)];
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
                    // Mon prix de revente : l'app signale une unité qui coûterait plus qu'elle ne rapporte.
                    'prix_vente' => (int) $mien->prix_vente,
                    // Mon palier du même nom que son unité (carton) : la commande s'y lit.
                    'unite_commande' => collect($mien->paliers ?? [])->firstWhere(fn ($pl) => (float) $pl['contenance'] === (float) $c->contenance)['unite'] ?? null,
                ],
            ];
        })->values()->all();
    }

    /** Où en est ce revendeur chez ce grossiste : null (jamais lié), demande, acceptee, refusee. */
    public function statutLiaison(string $revendeurId, string $grossisteId): ?string
    {
        return LiaisonGrossiste::where('boutique_revendeur_id', $revendeurId)->where('boutique_grossiste_id', $grossisteId)->value('statut');
    }

    /** La liaison, demandée si elle n'existe pas : le grossiste est prévenu une fois. */
    public function liaison(string $revendeurId, string $grossisteId): LiaisonGrossiste
    {
        $liaison = LiaisonGrossiste::firstOrCreate(['boutique_revendeur_id' => $revendeurId, 'boutique_grossiste_id' => $grossisteId], ['statut' => 'demande']);
        if ($liaison->wasRecentlyCreated) {
            $grossiste = Boutique::withoutGlobalScopes()->find($grossisteId);
            $revendeur = Boutique::withoutGlobalScopes()->find($revendeurId);
            if (($proprietaire = User::find($grossiste?->proprietaire_id)) !== null) {
                $this->notifier->envoyer($proprietaire, 'Nouveau revendeur', "{$revendeur?->nom} veut vous commander. Acceptez-le pour qu'il voie votre catalogue et vos prix.", '/commandes-recues', 'commande', false);
            }
        }

        return $liaison;
    }

    /**
     * Les grossistes de Ngoni, pour « Ajouter un fournisseur » : boutiques qui
     * vendent en gros et reçoivent des commandes, du pays choisi (le mien par
     * défaut : un Ivoirien peut commander à Bamako). Recherche par nom de la
     * boutique, nom ou numéro du commerçant.
     *
     * @return list<array<string, mixed>>
     */
    public function annuaire(?string $recherche = null, ?string $pays = null): array
    {
        $moi = Boutique::withoutGlobalScopes()->findOrFail($this->tenant->boutiqueId());
        $pays = Country::tryFrom(strtoupper((string) $pays))?->value ?? $moi->pays;
        $texte = trim((string) $recherche);
        $chiffres = preg_replace('/\D+/', '', $texte);
        $terme = '%'.str_replace(['%', '_'], '', $texte).'%';
        $boutiques = Boutique::withoutGlobalScopes()
            ->whereKeyNot($moi->id)->where('pays', $pays)->where('recoit_commandes', true)
            ->whereIn('mode_vente', ['detail_gros', 'gros'])
            ->when($texte !== '', fn ($q) => $q->where(fn ($q) => $q->where('nom', 'like', $terme)
                ->orWhereIn('proprietaire_id', User::where('name', 'like', $terme)->select('id'))
                ->when(strlen((string) $chiffres) >= 4, fn ($q) => $q->orWhere('telephone', 'like', '%'.$chiffres.'%')
                    ->orWhereIn('proprietaire_id', User::where('phone', 'like', '%'.$chiffres.'%')->select('id')))))
            ->with('proprietaire:id,name')->orderBy('nom')->limit(30)->get();
        $liaisons = LiaisonGrossiste::where('boutique_revendeur_id', $moi->id)->pluck('statut', 'boutique_grossiste_id');
        $dejaFournisseurs = Fournisseur::whereIn('boutique_liee_id', $boutiques->pluck('id'))->pluck('boutique_liee_id')->flip();

        return $boutiques->map(fn (Boutique $b) => [
            'id' => $b->id, 'nom' => $b->nom, 'adresse' => $b->adresse, 'telephone' => $b->telephone, 'pays' => $b->pays, 'devise' => $b->devise,
            'commercant' => $b->proprietaire?->name,
            'liaison' => $liaisons[$b->id] ?? null,
            'deja_fournisseur' => $dejaFournisseurs->has($b->id),
        ])->values()->all();
    }

    /** « Ajouter » dans l'annuaire : le fournisseur créé (ou retrouvé), et la demande envoyée. */
    public function ajouterDepuisAnnuaire(Boutique $grossiste): Fournisseur
    {
        $moi = (string) $this->tenant->boutiqueId();
        abort_if($grossiste->id === $moi || ! $grossiste->recoit_commandes, 404);
        $fournisseur = Fournisseur::where('boutique_liee_id', $grossiste->id)->first()
            ?? Fournisseur::create(['nom' => $grossiste->nom, 'telephone' => $grossiste->telephone]);
        $fournisseur->forceFill(['boutique_liee_id' => $grossiste->id])->save();
        $this->liaison($moi, $grossiste->id);

        return $fournisseur;
    }

    /** Grossiste : ses revendeurs, les demandes d'abord. @return Collection<int, LiaisonGrossiste> */
    public function revendeurs(): Collection
    {
        return LiaisonGrossiste::where('boutique_grossiste_id', $this->tenant->boutiqueId())->with('revendeur:id,nom,telephone')
            ->orderByRaw("CASE statut WHEN 'demande' THEN 0 WHEN 'acceptee' THEN 1 ELSE 2 END")->latest()->get();
    }

    /** Le grossiste accepte (catalogue visible) ou refuse (ou retire) un revendeur. */
    public function decider(int $liaisonId, bool $accepter): LiaisonGrossiste
    {
        $liaison = LiaisonGrossiste::where('boutique_grossiste_id', $this->tenant->boutiqueId())->findOrFail($liaisonId);
        $liaison->update(['statut' => $accepter ? 'acceptee' : 'refusee']);
        if ($accepter && ($proprietaire = User::find($liaison->revendeur?->proprietaire_id)) !== null) {
            $nom = Boutique::withoutGlobalScopes()->whereKey($liaison->boutique_grossiste_id)->value('nom');
            $this->notifier->envoyer($proprietaire, 'Fournisseur accepté', "{$nom} vous a accepté : son catalogue et ses prix sont dans Achats → Commander.", '/achats', 'commande', false);
        }

        return $liaison;
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
    public static function contenanceProposee(Produit $mien, Produit $sien, ?int $prix = null): float
    {
        $contenance = 1.0;
        $unite = self::uniteNormalisee($sien->unite);
        if ($unite !== null && $unite !== self::uniteNormalisee($mien->unite)) {
            foreach ($mien->paliers ?? [] as $p) {
                if (self::uniteNormalisee($p['unite'] ?? null) === $unite) {
                    $contenance = (float) $p['contenance'];
                }
            }
        }

        // Contrôle par le prix : payer l'unité plus cher que je ne la revends,
        // c'est que les unités ne se répondent pas (son « sachet » à 3 600 F est
        // un carton). Le plus petit de mes paliers où le compte tombe juste.
        $prix ??= (int) $sien->prix_vente;
        $revente = (int) $mien->prix_vente;
        if ($revente > 0 && $prix / $contenance > $revente) {
            $paliers = collect($mien->paliers ?? [])->sortBy('contenance');
            foreach ($paliers as $p) {
                if ((float) $p['contenance'] > 1 && $prix / (float) $p['contenance'] <= $revente) {
                    return (float) $p['contenance'];
                }
            }
        }

        return $contenance;
    }

    private static function uniteNormalisee(?string $unite): ?string
    {
        $u = rtrim(mb_strtolower(trim((string) $unite)), 's');

        return $u === '' ? null : $u;
    }

    /**
     * L'article du grossiste devient le mien : même nom, même unité (si ma
     * boutique la connaît), son prix pour prix d'achat et, à défaut d'autre,
     * pour prix de vente — à corriger dans la fiche. Stock à zéro : la
     * réception le remplira. Un article du même nom chez moi est repris.
     */
    private function creerDepuisCatalogue(?Boutique $grossiste, string $produitGrossisteId): Produit
    {
        $article = $grossiste === null ? null : Produit::withoutGlobalScopes()->where('boutique_id', $grossiste->id)->where('actif', true)->find($produitGrossisteId);
        if ($article === null) {
            throw ValidationException::withMessages(['lignes' => ['Article introuvable chez ce fournisseur.']]);
        }
        $existant = Produit::query()->whereRaw('LOWER(TRIM(nom)) = ?', [mb_strtolower(trim($article->nom))])->first();
        if ($existant !== null) {
            return $existant;
        }
        $prix = (int) ($this->vendEnGros($grossiste) && $article->prix_gros !== null ? $article->prix_gros : $article->prix_vente);
        // Une unité que ma boutique ne connaît pas : l'article se vend à la pièce.
        $unite = filled($article->unite) && (isset(Quantite::UNITES[$article->unite]) || UniteBoutique::where('nom', $article->unite)->exists())
            ? $article->unite : null;
        $code = filled($article->code_barre) && ! Produit::query()->whereIn('code_barre', Produit::variantesCodeBarre((string) $article->code_barre))->exists()
            ? $article->code_barre : null;

        return Produit::create([
            'nom' => $article->nom,
            'unite' => $unite,
            'code_barre' => $code,
            'prix_achat' => $prix,
            'prix_vente' => (int) $article->prix_vente,
            'taux_tva' => 0,
            'stock' => 0,
            'actif' => true,
        ]);
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
            // Ce qui attend le grossiste d'abord : les nouvelles, puis celles à livrer.
            ->orderByRaw("CASE statut WHEN 'envoyee' THEN 0 WHEN 'confirmee' THEN 1 WHEN 'en_livraison' THEN 2 ELSE 3 END")
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
            // Confirmer sa commande, c'est accepter ce revendeur : il voit le catalogue.
            LiaisonGrossiste::updateOrCreate(
                ['boutique_revendeur_id' => $commande->boutique_id, 'boutique_grossiste_id' => $commande->boutique_grossiste_id],
                ['statut' => 'acceptee'],
            );
        });

        $this->prevenirRevendeur($commande, 'Commande confirmée', 'confirme votre commande n° '.$commande->numero.'.');

        return $commande->load('lignes');
    }

    /** La marchandise est partie : le revendeur sait qu'elle arrive. */
    public function expedier(CommandeFournisseur $commande): CommandeFournisseur
    {
        $this->exigerDestinataire($commande);
        if ($commande->statut !== 'confirmee') {
            throw ValidationException::withMessages(['commande' => ['Confirmez la commande avant de l’envoyer.']]);
        }
        $commande->update(['statut' => 'en_livraison', 'expediee_le' => now()]);
        $this->prevenirRevendeur($commande, 'Commande en route', "a envoyé votre commande n° {$commande->numero} : elle arrive.");

        return $commande;
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
        if (! in_array($commande->statut, ['confirmee', 'en_livraison'], true)) {
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

            // Chez le revendeur : l'achat, au prix réellement facturé. Chaque
            // ligne reprend le montant de la vente au franc près : 32 500 F le
            // carton de 12 L ne doit pas devenir 12 × 2 708 F (40 F perdus).
            $prix = $vente->lignes->keyBy('produit_id');
            $montants = $vente->lignes->groupBy('produit_id')->map(fn ($ls) => (int) $ls->sum('total_ligne'));
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
                        // Un même article du grossiste sur deux lignes : son montant n'est compté qu'une fois.
                        'total' => $aLivrer->where('produit_grossiste_id', $l->produit_grossiste_id)->first()?->id === $l->id ? ($montants[$l->produit_grossiste_id] ?? null) : 0,
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

        return (filled($produit->code_barre) ? (clone $chez)->whereIn('code_barre', Produit::variantesCodeBarre($produit->code_barre))->first() : null)
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
