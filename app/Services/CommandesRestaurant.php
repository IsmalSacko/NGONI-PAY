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
use App\Models\Plan;
use App\Models\PosteRestaurant;
use App\Models\Produit;
use App\Models\RecetteRestaurant;
use App\Models\ReservationRestaurant;
use App\Models\TableRestaurant;
use App\Models\User;
use App\Models\Vente;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        if ($telephone && $type === 'sur_place') {
            throw ValidationException::withMessages(['type' => ['Une commande par téléphone est à emporter ou en livraison.']]);
        }
        // Par téléphone ou en livraison : on doit savoir qui rappeler.
        if (($telephone || $type === 'livraison') && empty($data['client_id'])) {
            throw ValidationException::withMessages(['client_id' => ['Choisissez le client (commande par téléphone ou livraison).']]);
        }
        if (! empty($data['client_id']) && ! Client::whereKey($data['client_id'])->exists()) {
            throw ValidationException::withMessages(['client_id' => ['Client introuvable.']]);
        }
        // Sur place : on sait à quelle table servir.
        if ($type === 'sur_place' && ! filled($data['table'] ?? null)) {
            throw ValidationException::withMessages(['table' => ['Choisissez la table.']]);
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

        // Différée : prévue plus tard que le temps de la cuisine (appel à 20 h pour 22 h),
        // elle partira seule en cuisine 30 minutes avant l'heure.
        $heure = filled($data['heure_prevue'] ?? null) ? Carbon::parse($data['heure_prevue']) : null;
        $envoyer = (bool) ($data['envoyer'] ?? true);
        $envoiPrevu = $envoyer && $heure !== null && $lignes !== [] && $heure->gt(now()->addMinutes(CommandeRestaurant::MINUTES_AVANT_DIFFEREE))
            ? $heure->copy()->subMinutes(CommandeRestaurant::MINUTES_AVANT_DIFFEREE) : null;

        // Offre Pro : la livraison et les commandes différées.
        if ($type === 'livraison') {
            $this->exigerPro($boutique, 'La livraison fait partie de l’offre Pro.');
        }
        if ($envoiPrevu !== null) {
            $this->exigerPro($boutique, 'Les commandes différées font partie de l’offre Pro.');
        }

        $commande = DB::transaction(function () use ($boutique, $data, $serveur, $type, $telephone, $lignes, $total, $acompte, $envoiPrevu): CommandeRestaurant {
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
                'envoi_prevu_le' => $envoiPrevu,
                'entree_file_le' => $envoiPrevu === null ? now() : null,
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

        if ($envoyer && $envoiPrevu === null) {
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
            // Une différée envoyée (à son heure, ou plus tôt à la main) entre dans la file maintenant.
            $commande->update([
                'envois' => $envoi, 'envoi_prevu_le' => null, 'entree_file_le' => $commande->entree_file_le ?? now(),
                'historique' => $commande->avecPas("envoi_{$envoi}", $agent),
            ]);
            $this->consommerIngredients($lignes);

            return $lignes->each(fn ($l) => $l->forceFill(['etat' => LigneCommandeRestaurant::EN_CUISINE, 'envoi' => $envoi]));
        });
    }

    /**
     * La cuisine (ou le bar) commence : à préparer → en préparation.
     *
     * @param  list<string>|null  $ids
     */
    public function commencer(CommandeRestaurant $commande, ?array $ids, ?int $minutes = null): CommandeRestaurant
    {
        $this->exigerEnCours($commande);
        $commande->lignes()->where('etat', LigneCommandeRestaurant::EN_CUISINE)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->update(['etat' => LigneCommandeRestaurant::EN_PREPARATION]);
        // « Prête dans 10 min » : l'heure annoncée aux serveurs (la plus tardive si plusieurs envois).
        if ($minutes !== null && $minutes > 0) {
            $vers = now()->addMinutes($minutes);
            if ($commande->prete_vers === null || $commande->prete_vers->isPast() || $vers->gt($commande->prete_vers)) {
                $commande->update(['prete_vers' => $vers]);
            }
        }

        return $commande->fresh();
    }

    /** Livraison : la commande prête part chez le client. */
    public function partirEnLivraison(CommandeRestaurant $commande, ?User $agent): CommandeRestaurant
    {
        $this->exigerEnCours($commande);
        if ($commande->type !== 'livraison') {
            throw ValidationException::withMessages(['commande' => ['Ce n’est pas une livraison.']]);
        }
        $commande->update(['en_livraison_le' => now(), 'historique' => $commande->avecPas('en_livraison', $agent)]);

        return $commande->fresh();
    }

    /**
     * Les commandes différées dont l'heure est venue partent en cuisine (30 min
     * avant l'heure prévue) ; le serveur qui l'a prise est prévenu. Sans
     * [toutes] : la boutique active seulement. Rend le nombre de commandes lancées.
     */
    public function lancerDifferees(bool $toutes = false): int
    {
        $dues = ($toutes ? CommandeRestaurant::withoutBoutiqueScope() : CommandeRestaurant::query())
            ->whereNotNull('envoi_prevu_le')->where('envoi_prevu_le', '<=', now())
            ->where('statut', '!=', CommandeRestaurant::ANNULEE)->get();
        $avant = $this->tenant->boutiqueId();
        foreach ($dues as $commande) {
            // La boutique de la commande : recettes et ingrédients sont les siens.
            $this->tenant->setBoutique($commande->boutique_id);
            try {
                $this->envoyerEnCuisine($commande, null);
                if ($serveur = $commande->serveur) {
                    $ou = $commande->type === 'livraison' ? 'Livraison' : ($commande->type === 'emporter' ? 'À emporter' : ($commande->table ?? 'Sur place'));
                    app(NotifierCompte::class)->envoyer($serveur, "{$ou} : commande différée en cuisine", "Commande {$commande->numero}, prévue à {$commande->heure_prevue?->format('H:i')}, est partie en cuisine.", '/salle', 'restaurant', email: false);
                }
            } catch (\Throwable $e) {
                Log::error('Commande différée non lancée', ['commande' => $commande->id, 'erreur' => $e->getMessage()]);
            }
        }
        if ($avant !== null) {
            $this->tenant->setBoutique($avant);
        } else {
            $this->tenant->forget();
        }

        return $dues->count();
    }

    /**
     * Plats prêts (par défaut : tous ceux pas encore prêts). Le serveur qui a
     * pris la commande est prévenu : « Table 12 : commande prête ».
     *
     * @param  list<string>|null  $ids
     */
    public function marquerPrets(CommandeRestaurant $commande, ?array $ids): CommandeRestaurant
    {
        $this->exigerEnCours($commande);
        $lignes = $commande->lignes()->whereIn('etat', LigneCommandeRestaurant::EN_COURS)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->get();
        if ($lignes->isEmpty()) {
            return $commande->fresh();
        }
        LigneCommandeRestaurant::whereIn('id', $lignes->pluck('id'))->update(['etat' => LigneCommandeRestaurant::PRETE, 'prete_le' => now()]);
        $this->prevenirServeur($commande, $lignes);

        return $commande->fresh();
    }

    /** @param  Collection<int, LigneCommandeRestaurant>  $lignes */
    private function prevenirServeur(CommandeRestaurant $commande, Collection $lignes): void
    {
        $serveur = $commande->serveur;
        if ($serveur === null) {
            return;
        }
        $ou = $commande->type === 'sur_place' ? ($commande->table ?? 'Sur place') : ($commande->type === 'livraison' ? 'Livraison' : 'À emporter');
        $toutPret = ! $commande->lignes()->whereIn('etat', [LigneCommandeRestaurant::ATTENTE, ...LigneCommandeRestaurant::EN_COURS])->exists();
        $plats = $lignes->map(fn ($l) => "{$l->quantite}× {$l->nom}")->implode(', ');
        app(NotifierCompte::class)->envoyer(
            $serveur,
            "{$ou} : ".($toutPret ? 'commande prête' : 'plats prêts'),
            "Commande {$commande->numero} — {$plats}.",
            '/salle',
            'restaurant',
            email: false,
        );
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
        // À emporter : rien ne sort sans passer par la caisse. (À table, on paie à la fin ;
        // en livraison, le livreur peut encaisser et rapporter l'argent à la caisse.)
        if ($commande->type === 'emporter' && $commande->reste() > 0) {
            throw ValidationException::withMessages(['commande' => ['Commande à emporter non payée : encaissez-la à la caisse avant de la remettre.']]);
        }
        $aServir = $commande->lignes()->whereIn('etat', [LigneCommandeRestaurant::ATTENTE, ...LigneCommandeRestaurant::EN_COURS, LigneCommandeRestaurant::PRETE]);
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
                $mixte = ! $credit && count($data['paiements'] ?? []) > 1 ? $this->partsMixtes($data['paiements'], $montant - $acompte) : null;
                $paiement = match (true) {
                    $credit => ['moyen_paiement' => $commande->moyen_acompte ?? 'especes', 'montant_paye' => $acompte, 'montant_recu' => $acompte],
                    // Paiement mixte : la vente porte le premier moyen et la liste des parts.
                    $mixte !== null => ['moyen_paiement' => $mixte[0]['moyen'], 'paiements' => $mixte],
                    default => ['moyen_paiement' => $data['moyen_paiement'], 'montant_recu' => $donne === null ? $montant : $acompte + (int) $donne],
                };
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
                $this->mouvement($commande, EncaissementRestaurant::POURBOIRE, $pourboire, $data['moyen_paiement'] ?? ($data['paiements'][0]['moyen'] ?? 'especes'), $caissier);
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

    /**
     * Parts d'un paiement mixte : leur somme doit faire exactement le dû.
     *
     * @param  list<array{moyen: string, montant: int}>  $parts
     * @return list<array{moyen: string, montant: int}>
     */
    private function partsMixtes(array $parts, int $du): array
    {
        $parts = array_values(array_filter(array_map(fn ($p) => ['moyen' => (string) $p['moyen'], 'montant' => (int) $p['montant']], $parts), fn ($p) => $p['montant'] > 0));
        if (array_sum(array_column($parts, 'montant')) !== $du) {
            throw ValidationException::withMessages(['paiements' => ['Les montants du paiement mixte doivent faire '.number_format($du, 0, ',', ' ').'.']]);
        }

        return $parts;
    }

    /**
     * Le plan de salle : chaque table et son état — libre, réservée (dans
     * les deux heures), occupée, ou en attente de paiement (tout est servi,
     * reste l'addition) — avec la commande qui l'occupe.
     *
     * @return list<array<string, mixed>>
     */
    public function salle(): array
    {
        $this->boutique();
        $this->lancerDifferees();
        $ouvertes = CommandeRestaurant::with('lignes')->where('type', 'sur_place')->whereNull('terminee_le')
            ->where('statut', '!=', CommandeRestaurant::ANNULEE)->whereNotNull('table')->get()->groupBy('table');
        $reservees = ReservationRestaurant::where('statut', ReservationRestaurant::PREVUE)->whereNotNull('table')
            ->whereBetween('le', [now()->subMinutes(30), now()->addHours(2)])->get()->keyBy('table');
        $etat = function (?CommandeRestaurant $c, ?ReservationRestaurant $r): string {
            if ($c === null) {
                return $r === null ? 'libre' : 'reservee';
            }
            $aServir = $c->lignes->contains(fn ($l) => in_array($l->etat, [LigneCommandeRestaurant::ATTENTE, ...LigneCommandeRestaurant::EN_COURS, LigneCommandeRestaurant::PRETE], true));

            return ! $aServir && $c->reste() > 0 ? 'a_payer' : 'occupee';
        };
        $place = fn (string $nom, ?string $zone, ?int $places, ?string $id = null) => [
            'table_id' => $id, 'nom' => $nom, 'zone' => $zone, 'places' => $places,
            'etat' => $etat($c = $ouvertes->get($nom)?->first(), $r = $reservees->get($nom)),
            'commande_id' => $c?->id, 'numero' => $c === null ? null : (string) $c->numero, 'couverts' => $c?->couverts,
            'total' => $c?->total, 'reste' => $c?->reste(), 'depuis' => $c?->created_at?->toIso8601String(),
            'pretes' => $c === null ? 0 : (int) $c->lignes->where('etat', LigneCommandeRestaurant::PRETE)->sum('quantite'),
            'reservation' => $r === null ? null : ['nom' => $r->nom, 'le' => $r->le->toIso8601String(), 'couverts' => $r->couverts],
        ];
        $toutes = TableRestaurant::orderBy('ordre')->orderBy('nom')->get();
        $connues = $toutes->pluck('nom')->all();
        // Une table rangée disparaît du plan, sauf si des clients y sont encore.
        $tables = $toutes->filter(fn ($t) => ! $t->rangee || $ouvertes->has($t->nom));

        return [
            ...$tables->map(fn ($t) => $place($t->nom, $t->zone, $t->places, $t->id))->values()->all(),
            // Une table tapée à la main (pas dans la liste) apparaît tant qu'elle est occupée.
            ...$ouvertes->keys()->reject(fn ($nom) => in_array($nom, $connues, true))->map(fn ($nom) => $place((string) $nom, null, null))->values()->all(),
        ];
    }

    /**
     * Les clients sont partis : la table se libère. Sans plat commandé, la
     * commande est annulée ; payée, elle est clôturée (servie). Une addition
     * non réglée bloque : l'argent passe d'abord par la caisse.
     *
     * @return bool true si la table est libre
     */
    public function liberer(string $table, ?User $agent): bool
    {
        $this->boutique();
        $commandes = CommandeRestaurant::with('lignes')->where('type', 'sur_place')->where('table', $table)
            ->whereNull('terminee_le')->where('statut', '!=', CommandeRestaurant::ANNULEE)->get();
        foreach ($commandes as $c) {
            $actives = $c->lignes->where('etat', '!=', LigneCommandeRestaurant::ANNULEE);
            if ($actives->isEmpty()) {
                $this->annuler($c, 'Table libérée sans commande', false, $agent);
            } elseif ($c->reste() === 0) {
                $this->servir($c, null, $agent);
                $c->refresh();
                if ($c->terminee_le === null) {
                    // Des plats encore en cuisine : servis d'office, la table se vide.
                    $c->lignes()->whereIn('etat', [LigneCommandeRestaurant::ATTENTE, ...LigneCommandeRestaurant::EN_COURS, LigneCommandeRestaurant::PRETE])
                        ->update(['etat' => LigneCommandeRestaurant::SERVIE, 'servie_le' => now()]);
                    $this->terminerSiFini($c);
                }
            } else {
                return false;
            }
        }

        return true;
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
        // Les groupes d'options de chaque plat (portion, cuisson…) et leurs règles.
        $groupes = OptionRestaurant::whereIn('produit_id', $produits->keys())->get()->groupBy(fn ($o) => $o->produit_id.'|'.($o->groupe ?? ''));
        // Le poste de chaque catégorie : la cuisine, ou le bar pour les boissons.
        $postes = PosteRestaurant::whereIn('categorie_produit_id', $produits->pluck('categorie_produit_id')->filter()->unique())->pluck('poste', 'categorie_produit_id');

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
            foreach ($groupes->filter(fn ($g, $cle) => str_starts_with($cle, $produit->id.'|')) as $groupe) {
                $nombre = $choisies->filter(fn ($o) => $o->groupe === $groupe->first()->groupe)->count();
                $titre = $groupe->first()->groupe ?? 'Options';
                if ($groupe->first()->choix_unique && $nombre > 1) {
                    throw ValidationException::withMessages(['lignes' => ["« {$produit->nom} » : un seul choix pour « {$titre} »."]]);
                }
                if ($groupe->first()->obligatoire && $nombre === 0) {
                    throw ValidationException::withMessages(['lignes' => ["« {$produit->nom} » : choisissez « {$titre} »."]]);
                }
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
                'poste' => $postes[$produit->categorie_produit_id] ?? PosteRestaurant::CUISINE,
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
        $resteAServir = $commande->lignes()->whereIn('etat', [LigneCommandeRestaurant::ATTENTE, ...LigneCommandeRestaurant::EN_COURS, LigneCommandeRestaurant::PRETE])->exists();
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

    private function exigerPro(Boutique $boutique, string $message): void
    {
        if (! app(AbonnementService::class)->permet($boutique, Plan::RESTAURANT_AVANCE)) {
            throw new HttpResponseException(response()->json([
                'message' => $message, 'code' => 'FONCTIONNALITE_NON_INCLUSE', 'fonctionnalite' => Plan::RESTAURANT_AVANCE,
            ], 403));
        }
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
