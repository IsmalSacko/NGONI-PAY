<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\Client;
use App\Models\CommandeRestaurant;
use App\Models\EncaissementRestaurant;
use App\Models\FormuleRestaurant;
use App\Models\IngredientRestaurant;
use App\Models\LigneCommandeRestaurant;
use App\Models\OptionRestaurant;
use App\Models\Produit;
use App\Models\RecetteRestaurant;
use App\Models\User;
use App\Models\Vente;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Commandes d'un restaurant : prise de commande (en salle ou par téléphone),
 * envoi en cuisine, plats prêts puis servis, et l'addition — payée tout de
 * suite ou plus tard, en une fois ou partagée — où naît la vente. Réservé aux
 * boutiques en activité restaurant : les autres n'y ont pas accès.
 */
class CommandesRestaurant
{
    public function __construct(private readonly TenantContext $tenant, private readonly SessionCaisseService $sessions) {}

    public function boutique(): Boutique
    {
        $boutique = Boutique::find($this->tenant->boutiqueId());
        if ($boutique === null || ! $boutique->estRestaurant()) {
            throw ValidationException::withMessages(['boutique' => ['Les commandes de restaurant sont réservées aux restaurants.']]);
        }

        return $boutique;
    }

    /**
     * Nouvelle commande. [envoyer] (par défaut) : ses plats partent en cuisine.
     *
     * @param  array<string, mixed>  $data
     */
    public function creer(array $data, User $serveur): CommandeRestaurant
    {
        $boutique = $this->boutique();
        if (! empty($data['reference_locale'])) {
            $existante = CommandeRestaurant::where('reference_locale', $data['reference_locale'])->first();
            if ($existante !== null) {
                return $existante;
            }
        }

        $type = $data['type'] ?? 'sur_place';
        $telephone = (bool) ($data['telephone'] ?? false);
        // Par téléphone ou en livraison : on doit savoir qui rappeler.
        if (($telephone || $type === 'livraison') && empty($data['client_id'])) {
            throw ValidationException::withMessages(['client_id' => ['Choisissez le client (commande par téléphone ou livraison).']]);
        }
        if (! empty($data['client_id']) && ! Client::whereKey($data['client_id'])->exists()) {
            throw ValidationException::withMessages(['client_id' => ['Client introuvable.']]);
        }
        if ($type === 'livraison' && ! filled($data['adresse'] ?? null)) {
            throw ValidationException::withMessages(['adresse' => ['Indiquez l’adresse de livraison.']]);
        }

        $lignes = $this->preparerLignes($data['lignes'] ?? []);
        $total = array_sum(array_column($lignes, 'total_ligne'));
        $acompte = (int) ($data['acompte'] ?? 0);
        if ($acompte > $total) {
            throw ValidationException::withMessages(['acompte' => ['L’acompte dépasse le total de la commande.']]);
        }

        $commande = DB::transaction(function () use ($boutique, $data, $serveur, $type, $telephone, $lignes, $total, $acompte): CommandeRestaurant {
            // Un numéro au hasard à 6 chiffres, jamais deux fois le même dans la boutique.
            Boutique::whereKey($boutique->id)->lockForUpdate()->first();
            do {
                $numero = random_int(100000, 999999);
            } while (CommandeRestaurant::withoutBoutiqueScope()->where('boutique_id', $boutique->id)->where('numero', $numero)->exists());

            $commande = CommandeRestaurant::create([
                'boutique_id' => $boutique->id,
                'numero' => $numero,
                'reference_locale' => $data['reference_locale'] ?? null,
                'type' => $type,
                'telephone' => $telephone,
                'table' => filled($data['table'] ?? null) ? trim((string) $data['table']) : null,
                'couverts' => $data['couverts'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'adresse' => $type === 'livraison' ? trim((string) $data['adresse']) : null,
                'heure_prevue' => $data['heure_prevue'] ?? null,
                'user_id' => $serveur->id,
                'statut' => CommandeRestaurant::OUVERTE,
                'total' => $total,
                'acompte' => $acompte,
                'moyen_acompte' => $acompte > 0 ? ($data['moyen_acompte'] ?? 'especes') : null,
                'notes' => $data['notes'] ?? null,
                'historique' => [['quoi' => 'ouverte', 'le' => now()->toIso8601String(), 'par' => $serveur->name]],
            ]);
            $this->creerLignes($commande, $lignes);
            if ($acompte > 0) {
                $this->mouvement($commande, EncaissementRestaurant::ACOMPTE, $acompte, $commande->moyen_acompte, $serveur);
            }

            return $commande;
        });

        if ($data['envoyer'] ?? true) {
            $this->envoyerEnCuisine($commande, $serveur);
        }

        return $commande->fresh();
    }

    /**
     * Des plats en plus, pendant le repas.
     *
     * @param  list<array<string, mixed>>  $lignes
     */
    public function ajouter(CommandeRestaurant $commande, array $lignes, User $serveur, bool $envoyer = true): CommandeRestaurant
    {
        $this->exigerEnCours($commande);
        $preparees = $this->preparerLignes($lignes);
        DB::transaction(function () use ($commande, $preparees): void {
            $this->creerLignes($commande, $preparees);
            $this->recalculer($commande);
        });
        if ($envoyer) {
            $this->envoyerEnCuisine($commande, $serveur);
        }

        return $commande->fresh();
    }

    /** Quantité ou note d'un plat pas encore envoyé en cuisine. */
    public function modifierLigne(CommandeRestaurant $commande, LigneCommandeRestaurant $ligne, ?int $quantite, ?string $note): CommandeRestaurant
    {
        $this->exigerLigne($commande, $ligne);
        if ($ligne->etat !== LigneCommandeRestaurant::ATTENTE) {
            throw ValidationException::withMessages(['ligne' => ['Ce plat est déjà parti en cuisine.']]);
        }
        $quantite ??= $ligne->quantite;
        $ligne->update(['quantite' => $quantite, 'total_ligne' => $ligne->prix_unitaire * $quantite, 'note' => $note === null ? $ligne->note : (trim($note) ?: null)]);
        $this->recalculer($commande);

        return $commande->fresh();
    }

    /** Retire un plat : effacé s'il n'est pas parti, sinon annulé (la cuisine le voit). */
    public function annulerLigne(CommandeRestaurant $commande, LigneCommandeRestaurant $ligne, User $agent): CommandeRestaurant
    {
        $this->exigerLigne($commande, $ligne);
        if ($ligne->vente_id !== null) {
            throw ValidationException::withMessages(['ligne' => ['Ce plat est déjà payé.']]);
        }
        DB::transaction(function () use ($commande, $ligne, $agent): void {
            if ($ligne->etat === LigneCommandeRestaurant::ATTENTE) {
                $ligne->delete();
            } else {
                $ligne->update(['etat' => LigneCommandeRestaurant::ANNULEE]);
                $commande->update(['historique' => $commande->avecPas('plat_annule', $agent)]);
            }
            $this->recalculer($commande);
        });

        return $commande->fresh();
    }

    /**
     * Les plats en attente partent en cuisine (un « envoi », imprimé en bon de
     * cuisine) ; leurs ingrédients sortent du stock. Rend les lignes envoyées.
     *
     * @return Collection<int, LigneCommandeRestaurant>
     */
    public function envoyerEnCuisine(CommandeRestaurant $commande, ?User $agent): Collection
    {
        $this->exigerEnCours($commande);

        return DB::transaction(function () use ($commande, $agent): Collection {
            $lignes = $commande->lignes()->where('etat', LigneCommandeRestaurant::ATTENTE)->get();
            if ($lignes->isEmpty()) {
                return $lignes;
            }
            $envoi = $commande->envois + 1;
            LigneCommandeRestaurant::whereIn('id', $lignes->pluck('id'))
                ->update(['etat' => LigneCommandeRestaurant::EN_CUISINE, 'envoi' => $envoi, 'envoyee_le' => now()]);
            $commande->update(['envois' => $envoi, 'historique' => $commande->avecPas("envoi_{$envoi}", $agent)]);
            $this->consommerIngredients($lignes);

            return $lignes->each(fn ($l) => $l->forceFill(['etat' => LigneCommandeRestaurant::EN_CUISINE, 'envoi' => $envoi]));
        });
    }

    /**
     * Plats prêts (par défaut : tous ceux en cuisine).
     *
     * @param  list<string>|null  $ids
     */
    public function marquerPrets(CommandeRestaurant $commande, ?array $ids): CommandeRestaurant
    {
        $this->exigerEnCours($commande);
        $commande->lignes()->where('etat', LigneCommandeRestaurant::EN_CUISINE)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->update(['etat' => LigneCommandeRestaurant::PRETE, 'prete_le' => now()]);

        return $commande->fresh();
    }

    /**
     * Plats servis, remis ou livrés (par défaut : tous ceux qui sont prêts ;
     * s'il n'y en a pas, tous ceux pas encore servis — une boisson sans cuisine).
     *
     * @param  list<string>|null  $ids
     */
    public function servir(CommandeRestaurant $commande, ?array $ids, ?User $agent): CommandeRestaurant
    {
        $this->exigerEnCours($commande);
        $aServir = $commande->lignes()->whereIn('etat', [LigneCommandeRestaurant::ATTENTE, LigneCommandeRestaurant::EN_CUISINE, LigneCommandeRestaurant::PRETE]);
        if ($ids !== null) {
            $aServir->whereIn('id', $ids);
        } elseif ((clone $aServir)->where('etat', LigneCommandeRestaurant::PRETE)->exists()) {
            $aServir->where('etat', LigneCommandeRestaurant::PRETE);
        }
        $aServir->update(['etat' => LigneCommandeRestaurant::SERVIE, 'servie_le' => now()]);
        $commande->update(['historique' => $commande->avecPas($commande->type === 'livraison' ? 'livree' : 'servie', $agent)]);
        $this->terminerSiFini($commande);

        return $commande->fresh();
    }

    /**
     * L'addition : la vente naît pour les plats choisis (par défaut, tout ce
     * qui reste à payer). L'acompte est déduit une fois ; [pourboire] entre
     * dans la caisse à part ; [credit] : le reste va sur le compte du client.
     *
     * @param  array{lignes?: ?list<string>, moyen_paiement: string, montant_donne?: ?int, pourboire?: ?int, credit?: bool}  $data
     * @return array{commande: CommandeRestaurant, vente: ?Vente}
     */
    public function payer(CommandeRestaurant $commande, array $data, User $caissier): array
    {
        if ($commande->statut === CommandeRestaurant::ANNULEE) {
            throw ValidationException::withMessages(['commande' => ['Cette commande est annulée.']]);
        }
        $lignes = $commande->lignes()->whereNull('vente_id')->where('etat', '!=', LigneCommandeRestaurant::ANNULEE)
            ->when(! empty($data['lignes']), fn ($q) => $q->whereIn('id', $data['lignes']))->get();
        if ($lignes->isEmpty()) {
            throw ValidationException::withMessages(['lignes' => ['Rien à payer.']]);
        }

        return DB::transaction(function () use ($commande, $lignes, $data, $caissier): array {
            $montant = (int) $lignes->sum('total_ligne');
            // L'acompte déjà versé (téléphone) se déduit, une seule fois.
            $acompteUtilise = (int) Vente::where('commande_restaurant_id', $commande->id)->sum('acompte_deduit');
            $acompte = min(max(0, $commande->acompte - $acompteUtilise), $montant);

            $vente = null;
            if ($montant > 0) {
                $donne = $data['montant_donne'] ?? null;
                $credit = (bool) ($data['credit'] ?? false) && $montant > $acompte;
                $paiement = $credit
                    ? ['moyen_paiement' => $commande->moyen_acompte ?? 'especes', 'montant_paye' => $acompte, 'montant_recu' => $acompte]
                    : ['moyen_paiement' => $data['moyen_paiement'], 'montant_recu' => $donne === null ? $montant : $acompte + (int) $donne];
                $vente = app(VenteService::class)->encaisser([
                    'client_id' => $commande->client_id,
                    'lignes' => $lignes->map(fn (LigneCommandeRestaurant $l) => $l->produit_id !== null
                        ? ['produit_id' => $l->produit_id, 'quantite' => $l->quantite, 'prix_fige' => $l->prix_unitaire, 'restaurant' => $l->nom]
                        // Plat retiré de la carte depuis : la ligne garde son nom et son prix.
                        : ['libelle' => $l->nom, 'prix_unitaire' => max(1, $l->prix_unitaire), 'quantite' => $l->quantite, 'taux_tva' => 0])->all(),
                    ...$paiement,
                    'acompte_deduit' => $acompte,
                    'commande_restaurant_id' => $commande->id,
                ], $caissier);
                LigneCommandeRestaurant::whereIn('id', $lignes->pluck('id'))->update(['vente_id' => $vente->id]);
            }
            // Rien à encaisser (plats offerts, à 0) : réglés d'office (voir recalculer).

            $pourboire = (int) ($data['pourboire'] ?? 0);
            if ($pourboire > 0) {
                $this->mouvement($commande, EncaissementRestaurant::POURBOIRE, $pourboire, $data['moyen_paiement'], $caissier);
            }

            $commande->update([
                'paye' => $commande->paye + $montant - $acompte,
                'pourboire' => $commande->pourboire + $pourboire,
                'historique' => $commande->avecPas('paiement', $caissier),
            ]);
            $this->recalculer($commande);

            return ['commande' => $commande->fresh(), 'vente' => $vente];
        });
    }

    /** Annulation : seulement si rien n'est payé ; [rembourser] rend l'acompte. */
    public function annuler(CommandeRestaurant $commande, ?string $motif, bool $rembourser, ?User $agent): CommandeRestaurant
    {
        $this->exigerEnCours($commande);
        if ($commande->lignes()->whereNotNull('vente_id')->exists()) {
            throw ValidationException::withMessages(['commande' => ['Une partie est déjà payée : retirez plutôt les plats un à un.']]);
        }
        DB::transaction(function () use ($commande, $motif, $rembourser, $agent): void {
            if ($rembourser && $commande->acompte > 0) {
                $this->mouvement($commande, EncaissementRestaurant::REMBOURSEMENT, $commande->acompte, $commande->moyen_acompte ?? 'especes', $agent);
            }
            $commande->lignes()->update(['etat' => LigneCommandeRestaurant::ANNULEE]);
            $commande->update([
                'statut' => CommandeRestaurant::ANNULEE, 'annulee_le' => now(), 'motif_annulation' => $motif,
                'historique' => $commande->avecPas(CommandeRestaurant::ANNULEE, $agent),
            ]);
        });

        return $commande->fresh();
    }

    /** Change de table (les clients se déplacent). */
    public function transferer(CommandeRestaurant $commande, string $table, ?User $agent): CommandeRestaurant
    {
        $this->exigerEnCours($commande);
        $commande->update(['table' => trim($table), 'historique' => $commande->avecPas('transfert', $agent)]);

        return $commande->fresh();
    }

    /** Réunit deux commandes (deux tables qui mangent ensemble) : les plats et l'acompte de [autre] passent sur [commande]. */
    public function fusionner(CommandeRestaurant $commande, CommandeRestaurant $autre, ?User $agent): CommandeRestaurant
    {
        $this->exigerEnCours($commande);
        $this->exigerEnCours($autre);
        if ($commande->is($autre)) {
            throw ValidationException::withMessages(['autre' => ['Choisissez une autre commande.']]);
        }
        if ($autre->paye > 0 || $autre->lignes()->whereNotNull('vente_id')->exists()) {
            throw ValidationException::withMessages(['autre' => ['Cette commande est déjà en partie payée.']]);
        }
        DB::transaction(function () use ($commande, $autre, $agent): void {
            $decalage = (int) $commande->lignes()->max('ordre') + 1;
            $autre->lignes()->update(['commande_id' => $commande->id, 'ordre' => DB::raw("ordre + {$decalage}")]);
            $autre->encaissements()->update(['commande_id' => $commande->id]);
            $commande->update([
                'acompte' => $commande->acompte + $autre->acompte,
                'moyen_acompte' => $commande->moyen_acompte ?? $autre->moyen_acompte,
                'couverts' => ($commande->couverts ?? 0) + ($autre->couverts ?? 0) ?: null,
                'historique' => $commande->avecPas('fusion', $agent),
            ]);
            $autre->update([
                'statut' => CommandeRestaurant::ANNULEE, 'annulee_le' => now(), 'acompte' => 0, 'total' => 0,
                'motif_annulation' => "Réunie avec la commande {$commande->numero}",
                'historique' => $autre->avecPas('fusion', $agent),
            ]);
            $this->recalculer($commande);
        });

        return $commande->fresh();
    }

    /**
     * Prix et noms des lignes demandées : le prix de la carte, plus les
     * options choisies ; une formule garde sa composition.
     *
     * @param  list<array<string, mixed>>  $lignes
     * @return list<array<string, mixed>>
     */
    private function preparerLignes(array $lignes): array
    {
        if ($lignes === []) {
            return [];
        }
        $produits = Produit::whereIn('id', array_column($lignes, 'produit_id'))->get()->keyBy('id');
        $ids = collect($lignes)->flatMap(fn ($l) => $l['options'] ?? [])->unique()->values();
        $options = OptionRestaurant::whereIn('id', $ids)->get()->keyBy('id');
        $formules = FormuleRestaurant::whereIn('produit_id', $produits->keys())->get()->keyBy('produit_id');
        $composants = Produit::whereIn('id', collect($lignes)->flatMap(fn ($l) => $l['composition'] ?? [])->unique()->values())->pluck('nom', 'id');

        $preparees = [];
        foreach ($lignes as $ligne) {
            $produit = $produits->get($ligne['produit_id'] ?? '');
            if ($produit === null || ! $produit->actif) {
                throw ValidationException::withMessages(['lignes' => ['Un plat demandé n’est plus à la carte.']]);
            }
            $choisies = collect($ligne['options'] ?? [])->map(fn ($id) => $options->get($id));
            if ($choisies->contains(fn ($o) => $o === null || $o->produit_id !== $produit->id)) {
                throw ValidationException::withMessages(['lignes' => ["Option inconnue pour « {$produit->nom} »."]]);
            }
            $composition = null;
            if ($formule = $formules->get($produit->id)) {
                $choix = array_values($ligne['composition'] ?? []);
                $etapes = $formule->etapes ?? [];
                if (count($choix) !== count($etapes)) {
                    throw ValidationException::withMessages(['lignes' => ["Choisissez un plat par étape pour « {$produit->nom} »."]]);
                }
                foreach ($etapes as $i => $etape) {
                    if (! in_array($choix[$i], $etape['produits'] ?? [], true)) {
                        throw ValidationException::withMessages(['lignes' => ["« {$etape['titre']} » : ce choix n’est pas dans la formule."]]);
                    }
                }
                $composition = array_map(fn ($id) => (string) $composants->get($id), $choix);
            }
            $prix = (int) $produit->prix_vente + (int) $choisies->sum('prix');
            $details = [...$choisies->pluck('nom')->all(), ...($composition ?? [])];
            $quantite = max(1, (int) ($ligne['quantite'] ?? 1));
            $preparees[] = [
                'produit_id' => $produit->id,
                'nom' => $produit->nom.($details === [] ? '' : ' ('.implode(', ', $details).')'),
                'quantite' => $quantite,
                'prix_unitaire' => $prix,
                'total_ligne' => $prix * $quantite,
                'options' => $choisies->isEmpty() ? null : $choisies->map(fn ($o) => ['nom' => $o->nom, 'prix' => $o->prix])->values()->all(),
                'composition' => $formule ? array_values($ligne['composition']) : null,
                'note' => filled($ligne['note'] ?? null) ? trim((string) $ligne['note']) : null,
            ];
        }

        return $preparees;
    }

    /** @param  list<array<string, mixed>>  $lignes */
    private function creerLignes(CommandeRestaurant $commande, array $lignes): void
    {
        $ordre = (int) $commande->lignes()->max('ordre');
        foreach ($lignes as $l) {
            $commande->lignes()->create([...$l, 'etat' => LigneCommandeRestaurant::ATTENTE, 'ordre' => ++$ordre]);
        }
    }

    /** Total des plats non annulés ; payée quand tous sont réglés ; terminée si payée et tout servi. */
    private function recalculer(CommandeRestaurant $commande): void
    {
        $actives = $commande->lignes()->where('etat', '!=', LigneCommandeRestaurant::ANNULEE)->get();
        $total = (int) $actives->sum('total_ligne');
        $toutPaye = $actives->isNotEmpty() && $actives->every(fn ($l) => $l->vente_id !== null || $l->total_ligne === 0)
            && $commande->paye + $commande->acompte >= $total;
        $commande->update([
            'total' => $total,
            'statut' => $toutPaye ? CommandeRestaurant::PAYEE : CommandeRestaurant::OUVERTE,
            'payee_le' => $toutPaye ? ($commande->payee_le ?? now()) : null,
        ]);
        $this->terminerSiFini($commande);
    }

    private function terminerSiFini(CommandeRestaurant $commande): void
    {
        $commande->refresh();
        $resteAServir = $commande->lignes()->whereIn('etat', [LigneCommandeRestaurant::ATTENTE, LigneCommandeRestaurant::EN_CUISINE, LigneCommandeRestaurant::PRETE])->exists();
        $commande->update(['terminee_le' => $commande->statut === CommandeRestaurant::PAYEE && ! $resteAServir ? ($commande->terminee_le ?? now()) : null]);
    }

    /**
     * Les ingrédients des plats envoyés sortent du stock (recettes ; une
     * formule, celles des plats choisis). Le stock peut passer sous zéro :
     * la cuisine ne s'arrête pas, l'alerte le dira.
     *
     * @param  Collection<int, LigneCommandeRestaurant>  $lignes
     */
    private function consommerIngredients(Collection $lignes): void
    {
        $besoins = [];
        foreach ($lignes as $l) {
            foreach ([$l->produit_id, ...($l->composition ?? [])] as $produitId) {
                if ($produitId !== null) {
                    $besoins[$produitId] = ($besoins[$produitId] ?? 0) + $l->quantite;
                }
            }
        }
        if ($besoins === []) {
            return;
        }
        foreach (RecetteRestaurant::whereIn('produit_id', array_keys($besoins))->get() as $r) {
            IngredientRestaurant::whereKey($r->ingredient_id)->decrement('quantite', round($r->quantite * $besoins[$r->produit_id], 3));
        }
    }

    private function mouvement(CommandeRestaurant $commande, string $type, int $montant, ?string $moyen, ?User $agent): void
    {
        EncaissementRestaurant::create([
            'boutique_id' => $commande->boutique_id, 'commande_id' => $commande->id, 'user_id' => $agent?->id,
            'session_caisse_id' => $agent === null ? null : $this->sessions->courante($agent)?->id,
            'type' => $type, 'montant' => $montant, 'moyen_paiement' => $moyen ?? 'especes',
        ]);
    }

    private function exigerEnCours(CommandeRestaurant $commande): void
    {
        if ($commande->statut === CommandeRestaurant::ANNULEE) {
            throw ValidationException::withMessages(['commande' => ['Cette commande est annulée.']]);
        }
        if ($commande->terminee_le !== null) {
            throw ValidationException::withMessages(['commande' => ['Cette commande est terminée.']]);
        }
    }

    private function exigerLigne(CommandeRestaurant $commande, LigneCommandeRestaurant $ligne): void
    {
        $this->exigerEnCours($commande);
        abort_unless($ligne->commande_id === $commande->id, 404);
    }
}
