<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CycleFacturation;
use App\Enums\StatutDemande;
use App\Http\Controllers\Controller;
use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\Plan;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\GestionPlans;
use App\Services\VenteAbonnement;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
    public function resume(): JsonResponse
    {
        $enCours = fn (Builder $q) => $q->where('est_actif', true)->where(fn ($w) => $w->whereNull('fin')->orWhereDate('fin', '>=', today()));
        $base = fn () => Abonnement::query()->avecCompte();
        $comptes = $base()->count();
        $actifs = $enCours($base())->count();
        $essais = $enCours($base()->where('plan', 'essai'))->count();

        return response()->json([
            'application' => ['code' => 'ngoni-caisse', 'nom' => 'Ngoni Caisse'],
            'comptes' => $comptes,
            'a_valider' => 0,
            'nouveaux_30j' => User::where('created_at', '>=', now()->subDays(30))->whereHas('boutiquesPossedees')->count(),
            'essais' => $essais,
            'essais_bientot_finis' => $enCours($base()->where('plan', 'essai'))->whereNotNull('fin')->whereDate('fin', '<=', today()->addDays(3))->count(),
            'abonnes' => $actifs - $essais,
            'echus' => $comptes - $actifs,
            'demandes_en_attente' => DemandeAbonnement::enAttente()->count(),
            'encaisse_mois' => DemandeAbonnement::where('statut', StatutDemande::Approuvee)->where('decide_le', '>=', now()->startOfMonth())
                ->groupBy('devise')->select('devise', DB::raw('SUM(montant) as total'))->get()
                ->map(fn ($r) => ['devise' => (string) $r->devise, 'total' => (int) $r->total])->values(),
        ]);
    }

    public function demandes(Request $request): JsonResponse
    {
        $statut = (string) $request->query('statut', 'en_attente');
        $demandes = DemandeAbonnement::with(['proprietaire.boutiquesPossedees:id,proprietaire_id,nom', 'demandeur:id,name,phone', 'boutique:id,nom'])
            ->when($statut !== 'toutes', fn ($q) => $q->where('statut', StatutDemande::tryFrom($statut) ?? StatutDemande::EnAttente))
            ->latest()->limit(300)->get();
        $noms = Plan::pluck('nom', 'code');

        return response()->json(['data' => $demandes->map(fn (DemandeAbonnement $d) => [
            'id' => (string) $d->id,
            'compte' => ['id' => (string) $d->user_id, 'nom' => $this->nomCompte($d->proprietaire, $d->boutique?->nom)],
            'formule' => $d->plan,
            'formule_nom' => $noms[$d->plan] ?? $d->plan,
            'mois' => $d->cycle?->mois() ?? (int) $d->mois,
            'montant' => (int) $d->montant,
            'devise' => $d->devise,
            'moyen' => $d->moyen,
            'telephone' => $d->telephone_contact ?: $d->proprietaire?->phone,
            'note' => trim(implode("\n", array_filter([$d->note, $d->preuve_note]))),
            'preuve' => $d->preuveExiste(),
            'statut' => $d->statut->value,
            'motif_refus' => $d->statut === StatutDemande::Refusee ? $d->note_decision : null,
            'recu_numero' => $d->recu_numero,
            'periode_debut' => $d->periode_debut?->toDateString(),
            'periode_fin' => $d->periode_fin?->toDateString(),
            'demandeur' => $d->demandeur?->name ?? $d->proprietaire?->name,
            'demandeur_telephone' => $d->demandeur?->phone ?? $d->proprietaire?->phone,
            'le' => $d->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function preuve(DemandeAbonnement $demande): Response
    {
        abort_unless($demande->preuveExiste(), 404);

        return Storage::disk('local')->response($demande->preuve_chemin);
    }

    public function approuver(DemandeAbonnement $demande, AbonnementService $service): JsonResponse
    {
        $this->refusMetier(fn () => $service->approuver($demande, $this->exploitant()));

        return response()->json(['demande' => ['id' => (string) $demande->id, 'statut' => $demande->fresh()?->statut->value, 'recu_numero' => $demande->fresh()?->recu_numero]]);
    }

    public function refuser(DemandeAbonnement $demande, Request $request, AbonnementService $service): JsonResponse
    {
        $motif = $request->validate(['motif' => ['required', 'string', 'max:500']])['motif'];
        $this->refusMetier(fn () => $service->refuser($demande, $this->exploitant(), $motif));

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
            ->when($filtre === 'essai', fn ($r) => $enCours($r->where('plan', 'essai')))
            ->when($filtre === 'abonne', fn ($r) => $enCours($r->where('plan', '!=', 'essai')))
            ->when($filtre === 'echu', fn ($r) => $r->where(fn ($w) => $w->where('est_actif', false)->orWhereDate('fin', '<', today())))
            ->latest()->limit(500)->get();

        $proprietaires = $abonnements->pluck('user_id');
        $membres = User::whereIn('boutique_id', Boutique::withoutGlobalScopes()->whereIn('proprietaire_id', $proprietaires)->select('id'))
            ->join('boutiques', 'boutiques.id', '=', 'users.boutique_id')
            ->groupBy('boutiques.proprietaire_id')->select('boutiques.proprietaire_id', DB::raw('COUNT(*) as n'))
            ->pluck('n', 'boutiques.proprietaire_id');
        $noms = Plan::pluck('nom', 'code');

        return response()->json(['data' => $abonnements->map(function (Abonnement $a) use ($membres, $noms) {
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
            ];
        })->values()]);
    }

    /** Ngoni Caisse ne fait pas valider ses comptes : rien à faire. */
    public function valider(): JsonResponse
    {
        return response()->json(['message' => 'Les comptes Ngoni Caisse n’ont pas besoin d’être validés.']);
    }

    public function accorder(User $user, Request $request, AbonnementService $service): JsonResponse
    {
        $data = $request->validate([
            'formule' => ['required', 'exists:plans,code'],
            'fin' => ['nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['fin.after_or_equal' => 'La fin doit être aujourd’hui ou plus tard.']);
        $this->refusMetier(fn () => $service->accorder($user, $data['formule'], isset($data['fin']) ? Carbon::parse($data['fin']) : null, $this->exploitant(), $data['note'] ?? null));

        return response()->json(['message' => "Abonnement de {$user->name} mis à jour."]);
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
            ];
        }

        return response()->json(['data' => $data]);
    }

    /** Régler une formule : on repart de l'état actuel et l'on change ce qui est envoyé. */
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
            'tarifs.*.mois' => ['required_with:tarifs', 'integer', 'in:1,3,6,12'],
            'tarifs.*.montant' => ['required_with:tarifs', 'integer', 'min:0'],
        ]);
        ['plans' => $plans, 'tarifs' => $tarifs] = $gestion->etat();
        $p = $plans[$plan->id];
        foreach (['nom' => 'nom', 'description' => 'description', 'jours_essai' => 'jours_essai', 'max_membres' => 'max_membres', 'actif' => 'est_actif'] as $cle => $champ) {
            if (array_key_exists($cle, $d)) {
                $p[$champ] = $d[$cle] ?? '';
            }
        }
        $cycles = collect(CycleFacturation::cases())->keyBy(fn (CycleFacturation $c) => $c->mois());
        foreach ($d['tarifs'] ?? [] as $t) {
            $cycle = $cycles->get((int) $t['mois']);
            if ($cycle !== null) {
                $tarifs[$plan->id][$cycle->value] = ['montant' => (string) $t['montant'], 'actif' => true];
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
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['message' => collect($e->errors())->flatten()->first() ?? 'Action refusée.'], 422));
        }
    }
}
