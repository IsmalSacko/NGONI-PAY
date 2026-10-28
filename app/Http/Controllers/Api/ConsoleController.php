<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CycleFacturation;
use App\Enums\MoyenPaiement;
use App\Enums\StatutDemande;
use App\Http\Controllers\Controller;
use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\JournalAbonnement;
use App\Models\Plan;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\Console\AbonnementsConsole;
use App\Services\GestionPlans;
use App\Services\RechercheDemandes;
use App\Services\VenteAbonnement;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ngoni Caisse vu par la Console Ngoni (toutes les applications IsmaelDev),
 * selon son contrat v1 : un « compte » est un propriétaire et ses boutiques
 * (l'abonnement est au propriétaire). Les gestes passent par les mêmes
 * services que la console de l'exploitant, au nom du premier exploitant.
 */
class ConsoleController extends Controller
{
    /** Ce que la console peut faire ici, en plus du contrat v1 (voir Ajouts v1.1). */
    private const CAPACITES = ['preuve', 'revoquer', 'approuver_a_vie', 'options_compte', 'recettes', 'historique'];

    /** Moyens d'un paiement reçu hors de l'application (valider, accorder). */
    private const MOYENS = [MoyenPaiement::Especes, MoyenPaiement::OrangeMoney, MoyenPaiement::MoovMoney, MoyenPaiement::Wave, MoyenPaiement::Carte, MoyenPaiement::Virement];

    /** Les comptes de l'exploitant, ses essais et le robot de Google Play : pas des clients. */
    private static function interne(User $u): bool
    {
        return (bool) $u->compte_interne || (bool) $u->est_admin_plateforme || (bool) $u->compte_test;
    }

    /** @param  Builder<User>  $u */
    private static function clients(Builder $u): Builder
    {
        return $u->where('compte_interne', false)->where('est_admin_plateforme', false)->where('compte_test', false);
    }

    public function resume(): JsonResponse
    {
        $enCours = fn (Builder $q) => $q->where('est_actif', true)->where(fn ($w) => $w->whereNull('fin')->orWhereDate('fin', '>=', today()));
        $base = fn () => Abonnement::query()->avecCompte()->whereHas('proprietaire', fn ($u) => self::clients($u));
        $comptes = $base()->count();
        $actifs = $enCours($base())->count();
        $essais = $enCours($base()->where('plan', 'essai'))->count();

        return response()->json([
            'application' => [
                'code' => 'ngoni-caisse',
                'nom' => 'Ngoni Caisse',
                'capacites' => self::CAPACITES,
                'moyens' => array_map(fn (MoyenPaiement $m) => ['code' => $m->value, 'libelle' => $m->label()], self::MOYENS),
            ],
            'comptes' => $comptes,
            'internes' => Abonnement::query()->avecCompte()->count() - $comptes,
            'a_valider' => 0,
            'nouveaux_30j' => self::clients(User::query())->where('created_at', '>=', now()->subDays(30))->whereHas('boutiquesPossedees')->count(),
            'essais' => $essais,
            'essais_bientot_finis' => $enCours($base()->where('plan', 'essai'))->whereNotNull('fin')->whereDate('fin', '<=', today()->addDays(3))->count(),
            'abonnes' => $actifs - $essais,
            'echus' => $comptes - $actifs,
            'demandes_en_attente' => DemandeAbonnement::enAttente()->whereHas('proprietaire', fn ($u) => self::clients($u))->count(),
            'encaisse_mois' => DemandeAbonnement::where('statut', StatutDemande::Approuvee)->where('decide_le', '>=', now()->startOfMonth())
                ->whereHas('proprietaire', fn ($u) => self::clients($u))
                ->groupBy('devise')->select('devise', DB::raw('SUM(montant) as total'))->get()
                ->map(fn ($r) => ['devise' => (string) $r->devise, 'total' => (int) $r->total])->values(),
        ]);
    }

    /** Mêmes onglets et même recherche que la console de l'exploitant (RechercheDemandes). */
    public function demandes(Request $request, RechercheDemandes $recherche): JsonResponse
    {
        $statut = (string) $request->query('statut', 'en_attente');
        $onglet = $statut === 'toutes' ? '' : (StatutDemande::tryFrom($statut) ?? StatutDemande::EnAttente)->value;
        $demandes = $recherche->requete($onglet, (string) $request->query('q', ''))
            ->with(['proprietaire.boutiquesPossedees:id,proprietaire_id,nom', 'decideur:id,name'])
            ->limit(300)->get();
        $noms = Plan::pluck('nom', 'code');

        return response()->json(['data' => $demandes->map(fn (DemandeAbonnement $d) => $this->ligneDemande($d, $noms))->values()]);
    }

    /**
     * @param  Collection<string, string>  $noms
     * @return array<string, mixed>
     */
    private function ligneDemande(DemandeAbonnement $d, Collection $noms): array
    {
        return [
            'id' => (string) $d->id,
            'compte' => ['id' => (string) $d->user_id, 'nom' => $this->nomCompte($d->proprietaire, $d->boutique?->nom)],
            'interne' => $d->proprietaire !== null && self::interne($d->proprietaire),
            'formule' => $d->plan,
            'formule_nom' => $noms[$d->plan] ?? $d->plan,
            'mois' => $d->mois ?: ($d->cycle?->mois() ?? 0),
            'montant' => (int) $d->montant,
            'devise' => $d->devise,
            // Le vrai moyen, prestataire en ligne compris (« jeko_wave ») ; et lisible.
            'moyen' => $d->moyen,
            'moyen_libelle' => blank($d->moyen) ? null : AbonnementsConsole::moyenLibelle($d->moyen),
            'telephone' => $d->telephone_contact ?: $d->proprietaire?->phone,
            'note' => $d->note,
            'preuve' => $d->preuveExiste(),
            'preuve_note' => $d->preuve_note,
            'reference' => $d->referencePaiement(),
            'frais' => (int) $d->frais_mobile,
            // Payée en ligne, validée sans personne.
            'automatique' => $d->statut === StatutDemande::Approuvee && $d->decide_par === null,
            'statut' => $d->statut->value,
            'motif_refus' => $d->statut === StatutDemande::Refusee ? $d->note_decision : null,
            'note_decision' => $d->note_decision,
            'par' => $d->decidePar(),
            'decide_le' => $d->decide_le?->toIso8601String(),
            'recu_numero' => $d->recu_numero,
            'periode_debut' => $d->periode_debut?->toDateString(),
            'periode_fin' => $d->periode_fin?->toDateString(),
            'demandeur' => $d->demandeur?->name ?? $d->proprietaire?->name,
            'demandeur_telephone' => $d->demandeur?->phone ?? $d->proprietaire?->phone,
            'le' => $d->created_at?->toIso8601String(),
        ];
    }

    public function preuve(DemandeAbonnement $demande): Response
    {
        abort_unless($demande->preuveExiste(), 404);

        return Storage::disk('local')->response($demande->preuve_chemin);
    }

    /**
     * Valider, au montant demandé ou à un montant négocié (`montant`, `note`) :
     * le reçu et la vente prennent le montant validé, le moyen et la
     * référence donnés ; le montant demandé reste écrit dans la décision.
     * `a_vie` : sans échéance, comme dans la console de l'exploitant.
     * Montant et validation vont ensemble : un refus ne laisse rien de changé.
     */
    public function approuver(DemandeAbonnement $demande, Request $request, AbonnementService $service): JsonResponse
    {
        $v = $request->validate([
            'montant' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'moyen' => ['nullable', 'string', Rule::in(array_map(fn (MoyenPaiement $m) => $m->value, self::MOYENS))],
            'reference' => ['nullable', 'string', 'max:120'],
            'a_vie' => ['sometimes', 'boolean'],
        ]);
        $par = $this->par($request);
        $this->refusMetier(fn () => DB::transaction(function () use ($demande, $v, $par, $service): void {
            $note = filled($v['note'] ?? null) ? (string) $v['note'] : null;
            $changements = ['decide_par_nom' => $par];
            if ($demande->statut === StatutDemande::EnAttente && isset($v['montant']) && (int) $v['montant'] !== (int) $demande->montant) {
                $avant = number_format((int) $demande->montant, 0, ',', ' ');
                $note = trim("Montant ajusté (demandé : {$avant} {$demande->devise}). ".($note ?? ''));
                $changements['montant'] = (int) $v['montant'];
            }
            if (filled($v['moyen'] ?? null)) {
                $changements['moyen'] = $v['moyen'];
            }
            if (filled($v['reference'] ?? null)) {
                $changements['reference'] = trim((string) $v['reference']);
            }
            $demande->update($changements);
            $service->approuver($demande, $this->exploitant(), $note, sansEcheance: (bool) ($v['a_vie'] ?? false));
        }));

        return response()->json(['demande' => $this->ligneDemande($demande->fresh(['proprietaire.boutiquesPossedees', 'boutique', 'demandeur', 'decideur']), Plan::pluck('nom', 'code'))]);
    }

    public function refuser(DemandeAbonnement $demande, Request $request, AbonnementService $service): JsonResponse
    {
        $motif = $request->validate(['motif' => ['required', 'string', 'max:500']])['motif'];
        $par = $this->par($request);
        $this->refusMetier(fn () => DB::transaction(function () use ($demande, $motif, $par, $service): void {
            $service->refuser($demande, $this->exploitant(), $motif);
            $demande->update(['decide_par_nom' => $par]);
        }));

        return response()->json(['demande' => ['id' => (string) $demande->id, 'statut' => StatutDemande::Refusee->value]]);
    }

    public function comptes(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $filtre = (string) $request->query('filtre', '');
        $enCours = fn ($w) => $w->where('est_actif', true)->where(fn ($x) => $x->whereNull('fin')->orWhereDate('fin', '>=', today()));

        $abonnements = Abonnement::query()->avecCompte()
            ->with(['proprietaire.boutiquesPossedees' => fn ($b) => $b->withoutGlobalScopes()->select('id', 'proprietaire_id', 'nom', 'pays', 'activite', 'created_at')])
            ->when($q !== '', fn ($r) => $r->whereHas('proprietaire', fn ($u) => $u->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")
                ->orWhereHas('boutiquesPossedees', fn ($b) => $b->withoutGlobalScopes()->where('nom', 'like', "%{$q}%"))))
            ->when($filtre === 'a_valider', fn ($r) => $r->whereRaw('1 = 0'))
            ->when($filtre === 'interne',
                fn ($r) => $r->whereHas('proprietaire', fn ($u) => $u->where(fn ($w) => $w->where('compte_interne', true)->orWhere('est_admin_plateforme', true)->orWhere('compte_test', true))),
                fn ($r) => $r->whereHas('proprietaire', fn ($u) => self::clients($u)))
            ->when($filtre === 'essai', fn ($r) => $enCours($r->where('plan', 'essai')))
            ->when($filtre === 'abonne', fn ($r) => $enCours($r->where('plan', '!=', 'essai')))
            ->when($filtre === 'echu', fn ($r) => $r->where(fn ($w) => $w->where('est_actif', false)->orWhereDate('fin', '<', today())))
            ->latest()->limit(500)->get();

        $membres = $this->membres($abonnements->pluck('user_id'));
        $noms = Plan::pluck('nom', 'code');

        return response()->json(['data' => $abonnements->map(fn (Abonnement $a) => $this->ligneCompte($a, $membres, $noms))->values()]);
    }

    /**
     * Membres des boutiques de chaque propriétaire.
     *
     * @param  Collection<int, string>  $proprietaires
     * @return Collection<string, int>
     */
    private function membres(Collection $proprietaires): Collection
    {
        return User::whereIn('boutique_id', Boutique::withoutGlobalScopes()->whereIn('proprietaire_id', $proprietaires)->select('id'))
            ->join('boutiques', 'boutiques.id', '=', 'users.boutique_id')
            ->groupBy('boutiques.proprietaire_id')->select('boutiques.proprietaire_id', DB::raw('COUNT(*) as n'))
            ->pluck('n', 'boutiques.proprietaire_id');
    }

    /**
     * @param  Collection<string, int>  $membres
     * @param  Collection<string, string>  $noms
     * @return array<string, mixed>
     */
    private function ligneCompte(Abonnement $a, Collection $membres, Collection $noms): array
    {
        $u = $a->proprietaire;
        $premiere = $u?->boutiquesPossedees->first();
        $activite = $premiere?->getAttribute('activite');

        return [
            'id' => (string) $a->user_id,
            'nom' => $this->nomCompte($u),
            'type' => $activite,
            'type_libelle' => $activite ? ucfirst((string) $activite) : 'Commerce',
            'pays' => $premiere?->pays,
            'responsable' => $u?->name,
            'telephone' => $u?->phone,
            'valide' => true,
            'cree_le' => $u?->created_at?->toDateString(),
            'membres' => (int) ($membres[$a->user_id] ?? 0),
            'formule' => $a->plan,
            'formule_nom' => $noms[$a->plan] ?? $a->plan,
            'fin' => $a->fin?->toDateString(),
            'en_cours' => $a->estEnCours(),
            'manuel' => (bool) $a->est_manuel,
            'note' => $a->note_admin,
            'interne' => $u !== null && self::interne($u),
        ];
    }

    /**
     * Fiche d'un compte : la ligne de /comptes, ses boutiques et leur
     * activité, le parrainage, l'historique de l'abonnement et ses options.
     */
    public function compte(User $user): JsonResponse
    {
        $a = Abonnement::where('user_id', $user->id)->with(['proprietaire.parrain:id,name', 'proprietaire.boutiquesPossedees' => fn ($q) => $q->withoutGlobalScopes()
            ->withCount(['ventes as nb_ventes' => fn ($v) => $v->withoutGlobalScopes()->where('statut', 'validee')])
            ->withMax(['ventes as derniere_vente' => fn ($v) => $v->withoutGlobalScopes()->where('statut', 'validee')], 'created_at')
            ->withSum(['ventes as total_30j' => fn ($v) => $v->withoutGlobalScopes()->where('statut', 'validee')->where('created_at', '>=', now()->subDays(30))], 'total')])
            ->first();
        abort_if($a === null, 404);
        $noms = Plan::pluck('nom', 'code');

        return response()->json([
            'compte' => $this->ligneCompte($a, $this->membres(collect([$user->id])), $noms),
            'details' => $this->details($a),
            'historique' => $this->historique($a, $noms),
            'options' => $this->options($a),
        ]);
    }

    /** @return list<array{libelle: string, valeur: string}> */
    private function details(Abonnement $a): array
    {
        $u = $a->proprietaire;
        $nombre = fn ($n) => number_format((int) $n, 0, ',', ' ');
        $details = [
            ['libelle' => 'Responsable', 'valeur' => (string) $u?->name],
            ['libelle' => 'Téléphone', 'valeur' => (string) $u?->phone],
            ['libelle' => 'E-mail', 'valeur' => (string) ($u?->email ?? '')],
            ['libelle' => 'Inscrit le', 'valeur' => (string) $u?->created_at?->format('d/m/Y')],
        ];
        foreach ($u?->boutiquesPossedees ?? [] as $b) {
            $details[] = ['libelle' => 'Boutique '.$b->nom, 'valeur' => implode(' · ', array_filter([
                $b->pays,
                $b->getAttribute('activite') ? ucfirst((string) $b->getAttribute('activite')) : null,
                $nombre($b->nb_ventes).' ventes',
                'CA 30 j : '.$nombre($b->total_30j).' '.$b->devise,
                $b->derniere_vente ? 'dernière vente le '.Carbon::parse($b->derniere_vente)->format('d/m/Y') : 'aucune vente',
            ]))];
        }
        $ca = $u?->boutiquesPossedees->groupBy('devise')->map(fn ($bs, $devise) => $nombre($bs->sum('total_30j')).' '.$devise)->implode(' + ');
        $details[] = ['libelle' => 'Chiffre d’affaires 30 jours', 'valeur' => $ca ?: '0'];
        $details[] = ['libelle' => 'Parrainé par', 'valeur' => (string) ($u?->parrain?->name ?? '—')];
        $details[] = ['libelle' => 'Filleuls', 'valeur' => (string) User::where('parraine_par', $a->user_id)->count()];
        if ((int) $a->jours_offerts > 0) {
            $details[] = ['libelle' => 'Jours de parrainage en attente', 'valeur' => (string) (int) $a->jours_offerts];
        }
        $accordees = array_map(fn (string $f) => Plan::FONCTIONNALITES[$f], $a->fonctionsAccordees());
        if ($accordees !== []) {
            $details[] = ['libelle' => 'Fonctions accordées en plus', 'valeur' => implode(', ', $accordees)];
        }

        return $details;
    }

    /**
     * Demandes, accords et révocations (journal), essai : du plus récent au
     * plus ancien. Un accord payé porte son reçu : sa demande n'est pas répétée.
     *
     * @param  Collection<string, string>  $noms
     * @return list<array<string, mixed>>
     */
    private function historique(Abonnement $a, Collection $noms): array
    {
        $journal = JournalAbonnement::where('user_id', $a->user_id)->with('demande')->get();
        $dejaVues = $journal->pluck('demande_id')->filter()->all();
        $lignes = [];

        foreach ($journal as $j) {
            $lignes[] = [
                'type' => $j->type,
                'le' => $j->created_at?->toIso8601String(),
                'formule' => $j->plan,
                'formule_nom' => $noms[$j->plan] ?? $j->plan,
                'debut' => $j->debut?->toDateString(),
                'fin' => $j->fin?->toDateString(),
                'montant' => $j->montant,
                'devise' => $j->devise,
                'moyen' => $j->moyen,
                'reference' => $j->reference,
                'recu_numero' => $j->demande?->recu_numero,
                'statut' => null,
                'par' => $j->par,
                'note' => $j->note,
            ];
        }

        $demandes = DemandeAbonnement::where('user_id', $a->user_id)->whereNotIn('id', $dejaVues)->with('decideur:id,name')->get();
        foreach ($demandes as $d) {
            $lignes[] = [
                'type' => $d->statut === StatutDemande::Approuvee ? 'paiement' : 'demande',
                'le' => ($d->decide_le ?? $d->created_at)?->toIso8601String(),
                'formule' => $d->plan,
                'formule_nom' => $noms[$d->plan] ?? $d->plan,
                'debut' => $d->periode_debut?->toDateString(),
                'fin' => $d->periode_fin?->toDateString(),
                'montant' => (int) $d->montant,
                'devise' => $d->devise,
                'moyen' => $d->moyen,
                'reference' => $d->referencePaiement(),
                'recu_numero' => $d->recu_numero,
                'statut' => $d->statut->value,
                'par' => $d->decidePar(),
                'note' => $d->note_decision ?? $d->note,
            ];
        }

        // L'essai, offert à l'inscription : l'abonnement est né avec lui.
        $lignes[] = [
            'type' => 'essai',
            'le' => $a->created_at?->toIso8601String(),
            'formule' => Plan::ESSAI,
            'formule_nom' => $noms[Plan::ESSAI] ?? 'Essai',
            'debut' => $a->created_at?->toDateString(),
            'fin' => $a->estEssai() ? $a->fin?->toDateString() : null,
            'montant' => null, 'devise' => null, 'moyen' => null, 'reference' => null, 'recu_numero' => null,
            'statut' => null, 'par' => null, 'note' => null,
        ];

        usort($lignes, fn ($x, $y) => strcmp((string) $y['le'], (string) $x['le']));

        return $lignes;
    }

    /**
     * Options du compte (descripteurs de champs) : les fonctions accordées en
     * plus de la formule, comme dans la console de l'exploitant.
     *
     * @return list<array<string, mixed>>
     */
    private function options(Abonnement $a): array
    {
        $plan = $a->planModele();

        return [[
            'cle' => 'fonctions_accordees',
            'libelle' => 'Fonctions accordées en plus de la formule',
            'type' => 'choix_multiple',
            'options' => collect(Plan::FONCTIONNALITES)
                ->reject(fn (string $l, string $f) => $plan?->inclut($f) ?? false)
                ->map(fn (string $l, string $f) => ['valeur' => $f, 'libelle' => $l])->values()->all(),
            'valeur' => $a->fonctionsAccordees(),
            'aide' => 'Celles de la formule '.($plan?->nom ?? $a->plan).' sont déjà incluses.',
        ]];
    }

    public function reglerOptions(User $user, Request $request, AbonnementService $service): JsonResponse
    {
        $d = $request->validate([
            'fonctions_accordees' => ['sometimes', 'array'],
            'fonctions_accordees.*' => ['string', Rule::in(array_keys(Plan::FONCTIONNALITES))],
        ]);
        abort_if($user->abonnement()->first() === null, 404);
        if (array_key_exists('fonctions_accordees', $d)) {
            $this->refusMetier(fn () => $service->reglerFonctions($user, array_values($d['fonctions_accordees'])));
        }

        return response()->json(['message' => "Options de {$user->name} enregistrées.", 'options' => $this->options($user->abonnement()->first())]);
    }

    /** Fin immédiate de l'abonnement, comme dans la console de l'exploitant. */
    public function revoquer(User $user, Request $request, AbonnementService $service): JsonResponse
    {
        $motif = $request->validate(['motif' => ['nullable', 'string', 'max:500']])['motif'] ?? null;
        abort_if($user->abonnement()->first() === null, 404);
        $service->revoquer($user, $this->exploitant(), $motif, $this->par($request));

        return response()->json(['message' => "Abonnement de {$user->name} révoqué : ses boutiques passent en lecture seule."]);
    }

    /**
     * Tout ce qui a été encaissé sur la période : demandes validées (en ligne
     * ou à la main) et accords payés, avec les frais du paiement mobile.
     */
    public function recettes(Request $request, AbonnementsConsole $console): JsonResponse
    {
        $d = $request->validate(['du' => ['nullable', 'date'], 'au' => ['nullable', 'date']]);
        $du = isset($d['du']) ? Carbon::parse($d['du'])->startOfDay() : now()->startOfMonth();
        $au = isset($d['au']) ? Carbon::parse($d['au'])->endOfDay() : now()->endOfDay();
        $requete = fn () => $console->requete(['periode' => 'tout'])
            ->whereHas('proprietaire', fn ($u) => self::clients($u))
            ->where('montant', '>', 0)->whereBetween('decide_le', [$du, $au]);
        $noms = Plan::pluck('nom', 'code');

        return response()->json([
            'data' => $requete()->with(['proprietaire.boutiquesPossedees:id,proprietaire_id,nom', 'decideur:id,name'])->limit(2000)->get()
                ->map(fn (DemandeAbonnement $r) => [
                    'id' => (string) $r->id,
                    'le' => $r->decide_le?->toIso8601String(),
                    'compte' => ['id' => (string) $r->user_id, 'nom' => $this->nomCompte($r->proprietaire, $r->boutique?->nom)],
                    'formule' => $r->plan,
                    'formule_nom' => $noms[$r->plan] ?? $r->plan,
                    'mois' => (int) $r->mois,
                    'montant' => (int) $r->montant,
                    'devise' => $r->devise,
                    'moyen' => $r->moyen,
                    'moyen_libelle' => AbonnementsConsole::moyenLibelle($r->moyen),
                    'canal' => AbonnementsConsole::canalDe($r->moyen),
                    'reference' => $r->referencePaiement(),
                    'frais' => (int) $r->frais_mobile,
                    'recu_numero' => $r->recu_numero,
                    'automatique' => $r->decide_par === null,
                    'par' => $r->decidePar(),
                ])->values(),
            'totaux' => $requete()->reorder()->groupBy('devise')
                ->select('devise', DB::raw('SUM(montant) as total'), DB::raw('SUM(frais_mobile) as frais'))->get()
                ->map(fn ($t) => ['devise' => (string) $t->devise, 'total' => (int) $t->total, 'frais' => (int) $t->frais])->values(),
        ]);
    }

    /** Ngoni Caisse ne fait pas valider ses comptes : rien à faire. */
    public function valider(): JsonResponse
    {
        return response()->json(['message' => 'Les comptes Ngoni Caisse n’ont pas besoin d’être validés.']);
    }

    /** Marquer un compte comme étant à l'exploitant (ou plus) : hors des chiffres. */
    public function marquerInterne(User $user, Request $request): JsonResponse
    {
        $interne = (bool) $request->validate(['interne' => ['required', 'boolean']])['interne'];
        $user->forceFill(['compte_interne' => $interne])->save();

        return response()->json(['message' => $interne ? "{$user->name} est marqué comme à vous." : "{$user->name} redevient un client."]);
    }

    /**
     * Accorder une formule à la main. `fin` est prise telle quelle (null :
     * sans échéance). Avec un `montant` : reçu numéroté et vente, comme un
     * paiement. Une demande en attente du compte est soldée.
     */
    public function accorder(User $user, Request $request, AbonnementService $service): JsonResponse
    {
        $data = $request->validate([
            'formule' => ['required', 'exists:plans,code'],
            'debut' => ['nullable', 'date'],
            'fin' => ['nullable', 'date', 'after_or_equal:today', ...($request->filled('debut') ? ['after_or_equal:debut'] : [])],
            'note' => ['nullable', 'string', 'max:255'],
            'montant' => ['nullable', 'integer', 'min:0'],
            'moyen' => ['nullable', 'string', Rule::in(array_map(fn (MoyenPaiement $m) => $m->value, self::MOYENS))],
            'reference' => ['nullable', 'string', 'max:120'],
        ], ['fin.after_or_equal' => 'La fin doit être aujourd’hui (et après le début) ou plus tard.']);
        $recu = null;
        $this->refusMetier(function () use ($service, $user, $data, $request, &$recu): void {
            $service->accorder(
                $user,
                $data['formule'],
                isset($data['fin']) ? Carbon::parse($data['fin']) : null,
                $this->exploitant(),
                $data['note'] ?? null,
                debut: isset($data['debut']) ? Carbon::parse($data['debut']) : null,
                paiement: ['montant' => $data['montant'] ?? null, 'moyen' => $data['moyen'] ?? null, 'reference' => filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null],
                par: $this->par($request),
            );
            $recu = JournalAbonnement::where('user_id', $user->id)->latest('id')->first()?->demande?->recu_numero;
        });

        return response()->json(['message' => "Abonnement de {$user->name} mis à jour.", 'recu_numero' => $recu]);
    }

    public function formules(GestionPlans $gestion): JsonResponse
    {
        ['plans' => $plans, 'tarifs' => $tarifs] = $gestion->etat();
        $data = [];
        foreach ($plans as $id => $p) {
            $prix = [];
            foreach ($tarifs[$id] ?? [] as $cycle => $t) {
                if ($t['montant'] !== '' && $t['actif']) {
                    $prix[] = ['mois' => CycleFacturation::from($cycle)->mois(), 'montant' => (int) $t['montant'], 'devise' => 'XOF'];
                }
            }
            // L'abonnement à vie : un tarif « mois 0 ».
            if (! $p['essai'] && $p['prix_a_vie'] !== '') {
                $prix[] = ['mois' => 0, 'montant' => (int) $p['prix_a_vie'], 'devise' => 'XOF'];
            }
            $data[] = [
                'code' => $p['code'],
                'nom' => $p['nom'],
                'description' => $p['description'] !== '' ? $p['description'] : null,
                'essai' => (bool) $p['essai'],
                'jours_essai' => $p['jours_essai'] !== '' ? (int) $p['jours_essai'] : 0,
                'max_membres' => $p['max_membres'] !== '' ? (int) $p['max_membres'] : null,
                'actif' => (bool) $p['est_actif'],
                'inclus' => array_values(array_map(
                    fn (string $f) => Plan::FONCTIONNALITES[$f] ?? $f,
                    array_keys(array_filter($p['fonctionnalites'])),
                )),
                'tarifs' => $prix,
                'champs' => $this->champsFormule($p),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Réglages d'une formule propres à Ngoni Caisse (descripteurs de champs) :
     * boutiques au plus, fonctions incluses, et le texte et la période de
     * l'offre à vie (son prix est le tarif « mois 0 »). L'essai couvre tout.
     *
     * @param  array<string, mixed>  $p  une formule de GestionPlans::etat()
     * @return list<array<string, mixed>>
     */
    private function champsFormule(array $p): array
    {
        $champs = [[
            'cle' => 'max_boutiques', 'libelle' => 'Boutiques au plus', 'type' => 'nombre',
            'valeur' => $p['max_boutiques'] !== '' ? (int) $p['max_boutiques'] : null, 'aide' => 'Vide = illimité',
        ]];
        if ($p['essai']) {
            return $champs;
        }

        return [
            ...$champs,
            [
                'cle' => 'fonctionnalites', 'libelle' => 'Fonctions incluses', 'type' => 'choix_multiple',
                'options' => collect(Plan::FONCTIONNALITES)->map(fn (string $l, string $f) => ['valeur' => $f, 'libelle' => $l])->values()->all(),
                'valeur' => array_keys(array_filter($p['fonctionnalites'])),
                'aide' => 'La caisse, le catalogue, les stocks et les clients sont dans toutes les formules.',
            ],
            ['cle' => 'texte_a_vie', 'libelle' => 'Offre à vie : texte', 'type' => 'texte', 'valeur' => $p['texte_a_vie'] !== '' ? $p['texte_a_vie'] : null, 'aide' => 'Son prix est le tarif « à vie ».'],
            ['cle' => 'a_vie_debut', 'libelle' => 'Offre à vie : à partir du', 'type' => 'date', 'valeur' => $p['a_vie_debut'] !== '' ? $p['a_vie_debut'] : null, 'aide' => 'Vide = dès maintenant'],
            ['cle' => 'a_vie_fin', 'libelle' => 'Offre à vie : jusqu’au', 'type' => 'date', 'valeur' => $p['a_vie_fin'] !== '' ? $p['a_vie_fin'] : null, 'aide' => 'Vide = sans fin'],
        ];
    }

    /**
     * Régler une formule : on repart de l'état actuel et l'on change ce qui
     * est envoyé. Dans `tarifs`, `montant: null` retire la durée et `mois: 0`
     * est l'abonnement à vie ; `champs` : voir champsFormule().
     */
    public function modifierFormule(string $code, Request $request, GestionPlans $gestion): JsonResponse
    {
        $plan = Plan::where('code', $code)->firstOrFail();
        $d = $request->validate([
            'nom' => ['sometimes', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'jours_essai' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'max_membres' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'actif' => ['sometimes', 'boolean'],
            'tarifs' => ['sometimes', 'array'],
            'tarifs.*.mois' => ['required_with:tarifs', 'integer', 'in:0,1,3,6,12'],
            'tarifs.*.montant' => ['present', 'nullable', 'integer', 'min:0'],
            'champs' => ['sometimes', 'array'],
            'champs.max_boutiques' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'champs.fonctionnalites' => ['sometimes', 'array'],
            'champs.fonctionnalites.*' => ['string', Rule::in(array_keys(Plan::FONCTIONNALITES))],
            'champs.texte_a_vie' => ['sometimes', 'nullable', 'string', 'max:255'],
            'champs.a_vie_debut' => ['sometimes', 'nullable', 'date'],
            'champs.a_vie_fin' => ['sometimes', 'nullable', 'date'],
        ]);
        ['plans' => $plans, 'tarifs' => $tarifs] = $gestion->etat();
        $p = $plans[$plan->id];
        foreach (['nom' => 'nom', 'description' => 'description', 'jours_essai' => 'jours_essai', 'max_membres' => 'max_membres', 'actif' => 'est_actif'] as $cle => $champ) {
            if (array_key_exists($cle, $d)) {
                $p[$champ] = $d[$cle] ?? '';
            }
        }
        $champs = $d['champs'] ?? [];
        foreach (['max_boutiques', 'texte_a_vie', 'a_vie_debut', 'a_vie_fin'] as $cle) {
            if (array_key_exists($cle, $champs)) {
                $p[$cle] = $champs[$cle] ?? '';
            }
        }
        if (array_key_exists('fonctionnalites', $champs)) {
            $p['fonctionnalites'] = array_fill_keys((array) $champs['fonctionnalites'], true);
        }
        $cycles = collect(CycleFacturation::cases())->keyBy(fn (CycleFacturation $c) => $c->mois());
        foreach ($d['tarifs'] ?? [] as $t) {
            $montant = $t['montant'] ?? null;
            if ((int) $t['mois'] === 0) {
                $p['prix_a_vie'] = $montant === null || (int) $montant === 0 ? '' : (string) $montant;

                continue;
            }
            $cycle = $cycles->get((int) $t['mois']);
            if ($cycle !== null) {
                // Sans montant : la durée n'est plus proposée.
                $tarifs[$plan->id][$cycle->value] = $montant === null
                    ? ['montant' => '', 'actif' => false]
                    : ['montant' => (string) $montant, 'actif' => true];
            }
        }
        $this->refusMetier(fn () => $gestion->enregistrer([$plan->id => $p], array_intersect_key($tarifs, [$plan->id => true])));

        return $this->formules($gestion);
    }

    /**
     * L'abonnement payé d'une autre application, approuvé dans la console :
     * une vente dans la boutique de l'exploitant (comme les abonnements
     * Ngoni Caisse). Rejoué, il ne double pas.
     */
    public function vente(Request $request, VenteAbonnement $ventes): JsonResponse
    {
        $d = $request->validate([
            'reference' => ['required', 'string', 'max:120'],
            'application' => ['required', 'string', 'max:40', 'alpha_dash'],
            'client' => ['required', 'array'],
            'client.nom' => ['required', 'string', 'max:120'],
            'client.telephone' => ['nullable', 'string', 'max:30'],
            'client.email' => ['nullable', 'email', 'max:120'],
            'client.adresse' => ['nullable', 'string', 'max:255'],
            'libelle' => ['required', 'string', 'max:255'],
            'montant' => ['required', 'integer', 'min:1'],
            'moyen' => ['nullable', 'string', 'max:30'],
        ]);
        $vente = $ventes->enregistrerExterne($d);
        if ($vente === null) {
            return response()->json(['message' => 'La boutique des ventes d’abonnement n’est pas réglée (ECAISSE_BOUTIQUE_VENTES_ABONNEMENTS).'], 422);
        }

        return response()->json(['vente' => ['id' => $vente->id, 'numero' => $vente->numero, 'numero_facture' => $vente->numero_facture, 'total' => (int) $vente->total]], 201);
    }

    /** « Boutique Awa » ; « Boutique Awa (+2) » quand le propriétaire en a plusieurs. */
    private function nomCompte(?User $u, ?string $boutique = null): string
    {
        $boutiques = $u?->boutiquesPossedees->pluck('nom') ?? collect();
        $premiere = $boutique ?? $boutiques->first() ?? $u?->name ?? 'Compte';

        return $boutiques->count() > 1 ? "{$premiere} (+".($boutiques->count() - 1).')' : (string) $premiere;
    }

    /** Qui agit dans la console (en-tête X-Console-Operateur) : « Console · Ismaila ». */
    private function par(Request $request): string
    {
        $nom = trim(mb_substr((string) $request->header('X-Console-Operateur', ''), 0, 100));

        return $nom === '' ? 'Console' : 'Console · '.$nom;
    }

    /** Les gestes de la console sont faits au nom du premier exploitant de la plateforme. */
    private function exploitant(): User
    {
        $exploitant = User::where('est_admin_plateforme', true)->orderBy('id')->first();
        abort_if($exploitant === null, 503, 'Aucun exploitant de la plateforme n’est déclaré.');

        return $exploitant;
    }

    /** Un refus métier (demande déjà traitée, plan inconnu…) devient un 422 lisible. */
    private function refusMetier(callable $geste): void
    {
        try {
            $geste();
        } catch (ValidationException $e) {
            throw new HttpResponseException(response()->json(['message' => collect($e->errors())->flatten()->first() ?? 'Action refusée.'], 422));
        }
    }
}
