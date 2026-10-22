<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\Coffre;
use App\Models\MouvementCoffre;
use App\Models\Plan;
use App\Models\User;
use App\Support\Fuseau;
use App\Support\Money\Montant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Le coffre : une partie de la recette mise de côté.
 *
 * La base d'un versement automatique est ce qui a été réellement encaissé dans
 * la journée, tous moyens de paiement confondus (le total « encaissé » des
 * rapports : ventes payées et crédits remboursés, sans les ventes annulées),
 * moins les pourboires, qui sont l'argent du personnel. Trois règles :
 * - rien un jour sans encaissement ;
 * - jamais plus que ce qui a été encaissé : les coffres, pris dans l'ordre de
 *   leur création, se partagent au plus la base du jour ;
 * - un seul versement par coffre et par jour (contrainte unique en base), et
 *   jamais pour une journée antérieure à sa règle (regle_depuis).
 *
 * Mettre de côté n'est pas dépenser : le chiffre d'affaires et le bénéfice ne
 * bougent pas ; seul le « reste disponible » du pilotage baisse.
 */
class Coffres
{
    /** Type des notifications du versement du soir. */
    public const NOTIFICATION = 'coffre';

    /** Heure locale (pays de la boutique) du versement de la journée. */
    public const HEURE = 23;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Rapports $rapports,
        private readonly Journee $journee,
        private readonly AbonnementService $abonnements,
        private readonly NotifierCompte $notifier,
    ) {}

    /** Ce qui a été encaissé dans la journée, sans les pourboires (boutique du contexte). */
    public function baseDuJour(Carbon $jour): int
    {
        $encaisse = $this->rapports->periode($jour, $jour)['encaisse'];

        return max(0, (int) $encaisse['total'] - (int) $encaisse['pourboires']);
    }

    /**
     * La part de chaque coffre automatique pour une base donnée : jamais plus
     * que la base, partagée dans l'ordre des coffres.
     *
     * @param  Collection<int, Coffre>  $coffres
     * @return list<array{coffre: Coffre, montant: int}>
     */
    public function repartir(int $base, Collection $coffres): array
    {
        $parts = [];
        $reste = $base;
        foreach ($coffres as $coffre) {
            if ($reste <= 0) {
                break;
            }
            $voulu = match ($coffre->mode) {
                Coffre::FIXE => (int) $coffre->montant_fixe,
                Coffre::POURCENTAGE => (int) floor($base * (float) $coffre->pourcentage / 100),
                default => 0,
            };
            $montant = min(max(0, $voulu), $reste);
            if ($montant > 0) {
                $parts[] = ['coffre' => $coffre, 'montant' => $montant];
                $reste -= $montant;
            }
        }

        return $parts;
    }

    /**
     * Les coffres dont la règle automatique vaut pour cette journée, dans
     * l'ordre où ils se partagent la base.
     *
     * @return Collection<int, Coffre>
     */
    private function automatiquesDu(Carbon $jour): Collection
    {
        return Coffre::where('mode', '!=', Coffre::MANUEL)
            ->where(fn ($q) => $q->whereNull('regle_depuis')->orWhereDate('regle_depuis', '<=', $jour->toDateString()))
            ->orderBy('created_at')->orderBy('id')
            ->get();
    }

    /**
     * Ce que le soir mettra de côté pour la journée, sur ce qui est encaissé
     * maintenant (rappel à la fermeture de caisse).
     *
     * @return array{base: int, total: int, coffres: list<array{id: string, nom: string, montant: int, verse: bool}>}
     */
    public function prevision(Carbon $jour): array
    {
        $coffres = $this->automatiquesDu($jour);
        if ($coffres->isEmpty()) {
            return ['base' => 0, 'total' => 0, 'coffres' => []];
        }
        $verses = MouvementCoffre::where('type', MouvementCoffre::AUTOMATIQUE)
            ->whereDate('jour_automatique', $jour->toDateString())->pluck('montant', 'coffre_id');
        $base = $this->baseDuJour($jour);
        $lignes = array_map(fn (array $p) => [
            'id' => $p['coffre']->id, 'nom' => $p['coffre']->nom,
            'montant' => (int) ($verses[$p['coffre']->id] ?? $p['montant']),
            'verse' => isset($verses[$p['coffre']->id]),
        ], $this->repartir($base, $coffres));

        return ['base' => $base, 'total' => array_sum(array_column($lignes, 'montant')), 'coffres' => $lignes];
    }

    /**
     * Le versement automatique d'une journée, pour la boutique du contexte.
     * Rejouable : un coffre déjà servi ce jour-là ne l'est pas deux fois.
     *
     * @return list<MouvementCoffre> les versements créés par cet appel
     */
    public function verser(Carbon $jour): array
    {
        $coffres = $this->automatiquesDu($jour);
        if ($coffres->isEmpty()) {
            return [];
        }
        $dejaServis = MouvementCoffre::where('type', MouvementCoffre::AUTOMATIQUE)
            ->whereDate('jour_automatique', $jour->toDateString())->pluck('coffre_id')->all();
        if (count(array_diff($coffres->pluck('id')->all(), $dejaServis)) === 0) {
            return [];
        }

        $base = $this->baseDuJour($jour);
        $crees = [];
        // Le partage est recalculé sur tous les coffres, servis ou non : le
        // plafond (la base du jour) reste le même pour ceux qui restent.
        foreach ($this->repartir($base, $coffres) as ['coffre' => $coffre, 'montant' => $montant]) {
            if (in_array($coffre->id, $dejaServis, true)) {
                continue;
            }
            try {
                $crees[] = MouvementCoffre::create([
                    'coffre_id' => $coffre->id, 'type' => MouvementCoffre::AUTOMATIQUE, 'montant' => $montant,
                    'jour' => $jour->toDateString(), 'jour_automatique' => $jour->toDateString(), 'base' => $base,
                ]);
            } catch (QueryException $e) {
                // Deux passages en même temps : la contrainte unique a gardé le premier.
                if (! str_contains(strtolower($e->getMessage()), 'unique') && ! str_contains($e->getMessage(), '1062')) {
                    throw $e;
                }
            }
        }

        return $crees;
    }

    /**
     * Créer un coffre ([$coffre] null), ou changer son nom et sa règle : à tout
     * moment. Une règle changée vaut à partir de la journée en cours. Même
     * chemin pour l'application et le back-office.
     *
     * @param  array<string, mixed>  $donnees  nom, mode, montant_fixe, pourcentage
     */
    public function regler(?Coffre $coffre, array $donnees): Coffre
    {
        $data = Validator::make($donnees, [
            'nom' => ['required', 'string', 'max:40'],
            'mode' => ['required', Rule::in(Coffre::MODES)],
            'montant_fixe' => ['nullable', 'required_if:mode,'.Coffre::FIXE, 'integer', 'min:1', 'max:100000000000'],
            'pourcentage' => ['nullable', 'required_if:mode,'.Coffre::POURCENTAGE, 'numeric', 'min:0.5', 'max:100'],
        ], [], ['nom' => 'nom', 'montant_fixe' => 'somme par jour', 'pourcentage' => 'part des ventes'])->validate();

        // Seule la valeur de la règle choisie est gardée.
        $regle = [
            'mode' => $data['mode'],
            'montant_fixe' => $data['mode'] === Coffre::FIXE ? (int) $data['montant_fixe'] : null,
            'pourcentage' => $data['mode'] === Coffre::POURCENTAGE ? round((float) $data['pourcentage'], 2) : null,
        ];
        $aujourdhui = $this->journee->courante()->toDateString();
        if ($coffre === null) {
            return Coffre::create([...$regle, 'nom' => trim($data['nom']), 'regle_depuis' => $aujourdhui]);
        }
        $change = $regle['mode'] !== $coffre->mode || $regle['montant_fixe'] !== $coffre->montant_fixe
            || (float) $regle['pourcentage'] !== (float) $coffre->pourcentage;
        $coffre->update([...$regle, 'nom' => trim($data['nom']), 'regle_depuis' => $change ? $aujourdhui : $coffre->regle_depuis]);

        return $coffre->fresh();
    }

    /** Un coffre vide seulement : l'argent mis de côté ne disparaît pas d'un geste. */
    public function supprimer(Coffre $coffre): void
    {
        if ($this->solde($coffre) !== 0) {
            throw ValidationException::withMessages(['coffre' => ['Retirez d’abord l’argent du coffre, puis supprimez-le.']]);
        }
        DB::transaction(function () use ($coffre): void {
            $coffre->mouvements()->delete();
            $coffre->delete();
        });
    }

    /** Mettre de côté à la main, aujourd'hui (journée de vente en cours). */
    public function ajouter(Coffre $coffre, int $montant, User $auteur): MouvementCoffre
    {
        return MouvementCoffre::create([
            'coffre_id' => $coffre->id, 'user_id' => $auteur->id, 'type' => MouvementCoffre::AJOUT,
            'montant' => $montant, 'jour' => $this->journee->courante()->toDateString(),
        ]);
    }

    /** Reprendre de l'argent du coffre : jamais plus que son solde. */
    public function retirer(Coffre $coffre, int $montant, User $auteur): MouvementCoffre
    {
        return DB::transaction(function () use ($coffre, $montant, $auteur): MouvementCoffre {
            // Verrouille le coffre : deux retraits simultanés ne passent pas sous zéro.
            Coffre::whereKey($coffre->id)->lockForUpdate()->first();
            $solde = $this->solde($coffre);
            if ($montant > $solde) {
                throw ValidationException::withMessages(['montant' => [
                    'Le coffre ne contient que '.Montant::format($solde).'.',
                ]]);
            }

            return MouvementCoffre::create([
                'coffre_id' => $coffre->id, 'user_id' => $auteur->id, 'type' => MouvementCoffre::RETRAIT,
                'montant' => $montant, 'jour' => $this->journee->courante()->toDateString(),
            ]);
        });
    }

    public function solde(Coffre $coffre): int
    {
        return (int) ($this->soldes([$coffre->id])[$coffre->id] ?? 0);
    }

    /**
     * Le solde de chaque coffre (boutique du contexte).
     *
     * @param  list<string>|null  $ids
     * @return array<string, int>
     */
    public function soldes(?array $ids = null): array
    {
        return MouvementCoffre::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('coffre_id', $ids))
            ->selectRaw("coffre_id, SUM(CASE WHEN type = 'retrait' THEN -montant ELSE montant END) as solde")
            ->groupBy('coffre_id')
            ->pluck('solde', 'coffre_id')
            ->map(fn ($s) => (int) $s)
            ->all();
    }

    /**
     * Le chemin de l'argent pour le pilotage : ce qui est parti dans les
     * coffres sur la période, et ce qui en a été repris.
     *
     * @return array{mis_de_cote: int, repris: int, net: int, par_coffre: list<array{id: string, nom: string, mis_de_cote: int, repris: int}>}
     */
    public function resume(Carbon $du, Carbon $au): array
    {
        $lignes = MouvementCoffre::with('coffre:id,nom')
            ->whereDate('jour', '>=', $du->toDateString())->whereDate('jour', '<=', $au->toDateString())
            ->get()->groupBy('coffre_id');
        $parCoffre = $lignes->map(fn (Collection $m, string $id) => [
            'id' => $id,
            'nom' => (string) $m->first()->coffre?->nom,
            'mis_de_cote' => (int) $m->where('type', '!=', MouvementCoffre::RETRAIT)->sum('montant'),
            'repris' => (int) $m->where('type', MouvementCoffre::RETRAIT)->sum('montant'),
        ])->sortByDesc('mis_de_cote')->values()->all();
        $mis = array_sum(array_column($parCoffre, 'mis_de_cote'));
        $repris = array_sum(array_column($parCoffre, 'repris'));

        return ['mis_de_cote' => $mis, 'repris' => $repris, 'net' => $mis - $repris, 'par_coffre' => $parCoffre];
    }

    /**
     * Tâche horaire. À 23 h, heure du pays de chaque boutique, la journée est
     * versée ; la veille est rattrapée si la tâche n'a pas tourné (serveur
     * arrêté), sans jamais rien verser deux fois. Le propriétaire est prévenu.
     * Rend le nombre de boutiques servies.
     */
    public function passageDuSoir(CarbonInterface $instant): int
    {
        $ids = Coffre::withoutBoutiqueScope()->where('mode', '!=', Coffre::MANUEL)->distinct()->pluck('boutique_id');
        $precedente = $this->tenant->boutiqueId();
        $servies = 0;

        foreach (Boutique::whereIn('id', $ids)->get() as $boutique) {
            $local = Carbon::instance($instant->toDateTime())->setTimezone(Fuseau::pourPays($boutique->pays));
            $jours = [Carbon::parse($local->copy()->subDay()->toDateString())];
            if ($local->hour >= self::HEURE) {
                $jours[] = Carbon::parse($local->toDateString());
            }
            $this->tenant->setBoutique($boutique->id);
            try {
                if (! $this->abonnements->permet($boutique, Plan::COFFRE)) {
                    continue;
                }
                $verses = [];
                foreach ($jours as $jour) {
                    $verses = [...$verses, ...$this->verser($jour)];
                }
                if ($verses !== []) {
                    $this->prevenir($boutique, $verses);
                    $servies++;
                }
            } finally {
                $this->tenant->setBoutique($precedente);
            }
        }

        return $servies;
    }

    /** @param  list<MouvementCoffre>  $verses */
    private function prevenir(Boutique $boutique, array $verses): void
    {
        $proprietaire = $boutique->proprietaire_id ? User::find($boutique->proprietaire_id) : null;
        if ($proprietaire === null || ! $proprietaire->is_active) {
            return;
        }
        $devise = $boutique->devise ?: Montant::deviseActive();
        $f = fn (int $m) => Montant::format($m, $devise).' '.(in_array($devise, ['XOF', 'XAF'], true) ? 'F' : $devise);
        $total = array_sum(array_map(fn (MouvementCoffre $m) => $m->montant, $verses));
        $soldes = $this->soldes(array_values(array_unique(array_map(fn (MouvementCoffre $m) => $m->coffre_id, $verses))));
        $detail = implode(' · ', array_map(
            fn (MouvementCoffre $m) => $m->coffre->nom.' : '.$f($m->montant).' (total '.$f($soldes[$m->coffre_id] ?? 0).')',
            $verses,
        ));

        $this->notifier->envoyer(
            $proprietaire,
            $f($total).' mis de côté',
            $boutique->nom.' — '.$detail.'. Pensez à les ranger.',
            '/coffres',
            self::NOTIFICATION,
            email: false,
        );
    }
}
