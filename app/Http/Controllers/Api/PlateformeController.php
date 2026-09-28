<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\StatutDemande;
use App\Http\Controllers\Controller;
use App\Models\Abonnement;
use App\Models\Annonce;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\Plan;
use App\Models\User;
use App\Models\Vente;
use App\Services\AbonnementService;
use App\Services\ComptesPlateforme;
use App\Services\GestionAnnonces;
use App\Services\Plateforme\Activite;
use App\Services\SuppressionCompte;
use App\Support\Periode;
use App\Support\WhatsApp;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console de l'exploitant dans l'application : les mêmes gestes que la
 * console web (/plateforme), sur les mêmes services.
 */
class PlateformeController extends Controller
{
    public function tableau(): JsonResponse
    {
        $actifs = Abonnement::avecCompte()->where('est_actif', true)
            ->where(fn ($q) => $q->whereNull('fin')->orWhereDate('fin', '>=', today()))
            ->count();
        $ventesDuJour = Vente::withoutBoutiqueScope()->valides()->whereDate('created_at', today());

        return response()->json([
            'comptes' => Abonnement::avecCompte()->count(),
            'actifs' => $actifs,
            'expires' => Abonnement::avecCompte()->count() - $actifs,
            'par_plan' => Abonnement::avecCompte()->select('plan', DB::raw('COUNT(*) as n'))->groupBy('plan')->pluck('n', 'plan'),
            'boutiques' => Boutique::count(),
            'utilisateurs' => User::count(),
            'inscriptions_7j' => User::where('created_at', '>=', now()->subDays(7))->whereNotNull('boutique_id')->count(),
            'demandes' => DemandeAbonnement::enAttente()->count(),
            'ventes_jour' => (clone $ventesDuJour)->count(),
            // Franc CFA seulement : additionner francs et centimes d'euro n'aurait pas de sens.
            'montant_jour_fcfa' => (int) (clone $ventesDuJour)
                ->whereIn('boutique_id', Boutique::withoutGlobalScopes()->whereIn('devise', ['XOF', 'XAF'])->select('id'))
                ->sum('total'),
        ]);
    }

    /** En ligne, encaissements, boutiques actives, pays, appareils : la console de l'application. */
    public function activite(Request $request, Activite $activite): JsonResponse
    {
        return response()->json($activite->pour(Periode::depuis($request->query('periode'))));
    }

    public function demandes(Request $request): JsonResponse
    {
        $demandes = DemandeAbonnement::with(['proprietaire.abonnement', 'boutique', 'demandeur'])
            ->when($request->query('filtre', 'en_attente') === 'en_attente', fn ($q) => $q->enAttente())
            ->orderByRaw('CASE WHEN statut = ? THEN 0 ELSE 1 END', [StatutDemande::EnAttente->value])
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $demandes->map(fn (DemandeAbonnement $d) => [
            'id' => $d->id,
            'statut' => $d->statut->value,
            'plan' => $d->plan,
            'cycle' => $d->cycle->libelle(),
            'montant' => $d->montant,
            'devise' => $d->devise,
            'moyen' => $d->moyen,
            'note' => $d->note,
            'decision' => $d->note_decision,
            'preuve' => $d->preuveExiste(),
            'preuve_note' => $d->preuve_note,
            'cree_le' => $d->created_at?->toIso8601String(),
            'boutique' => $d->boutique?->nom,
            'proprietaire' => $d->proprietaire?->name,
            'telephone' => $d->telephone_contact ?: $d->proprietaire?->phone,
            'whatsapp' => WhatsApp::link($d->telephone_contact ?: $d->proprietaire?->phone),
            'demandeur' => $d->demandeur && $d->demandeur->id !== $d->proprietaire?->id ? $d->demandeur->name : null,
            'abonnement' => $this->abonnement($d->proprietaire?->abonnement),
        ])]);
    }

    /** Preuve de paiement : hors du disque public, servie à l'exploitant seul. */
    public function preuve(DemandeAbonnement $demande): Response
    {
        abort_unless($demande->preuveExiste(), 404);

        return Storage::disk('local')->response($demande->preuve_chemin);
    }

    public function approuver(DemandeAbonnement $demande, Request $request, AbonnementService $service): JsonResponse
    {
        $abonnement = $service->approuver($demande, $request->user());

        return response()->json(['message' => 'Demande approuvée.', 'abonnement' => $this->abonnement($abonnement)]);
    }

    public function refuser(DemandeAbonnement $demande, Request $request, AbonnementService $service): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:500']], ['motif.required' => 'Indiquez le motif : le commerçant le verra.']);
        $service->refuser($demande, $request->user(), $data['motif']);

        return response()->json(['message' => 'Demande refusée.']);
    }

    public function comptes(Request $request): JsonResponse
    {
        $needle = '%'.mb_strtolower((string) $request->query('recherche', '')).'%';
        $filtre = (string) $request->query('filtre', '');

        $abonnements = Abonnement::query()->avecCompte()
            ->with(['proprietaire' => fn ($q) => $q->select('users.*')->addSelect([
                'derniere_app' => DB::table('personal_access_tokens')->selectRaw('MAX(last_used_at)')->whereColumn('tokenable_id', 'users.id'),
            ]), 'proprietaire.boutiquesPossedees'])
            ->when($request->filled('recherche'), fn ($q) => $q->whereHas('proprietaire', fn ($u) => $u
                ->whereRaw('LOWER(name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(phone) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$needle])
                ->orWhereHas('boutiquesPossedees', fn ($b) => $b->whereRaw('LOWER(nom) LIKE ?', [$needle]))))
            ->when($filtre === 'actifs', fn ($q) => $q->where('est_actif', true)->where(fn ($w) => $w->whereNull('fin')->orWhereDate('fin', '>=', today())))
            ->when($filtre === 'expires', fn ($q) => $q->where(fn ($w) => $w->where('est_actif', false)->orWhereDate('fin', '<', today())))
            ->latest('updated_at')
            ->limit(100)
            ->get();

        return response()->json([
            'plans' => Plan::ordonnes()->get(['code', 'nom']),
            'data' => $abonnements->map(fn (Abonnement $a) => [
                'user_id' => $a->user_id,
                'nom' => $a->proprietaire?->name,
                'telephone' => $a->proprietaire?->phone,
                'email' => $a->proprietaire?->email,
                'whatsapp' => WhatsApp::link($a->proprietaire?->phone),
                'boutiques' => $a->proprietaire?->boutiquesPossedees->pluck('nom')->values() ?? [],
                'derniere_app' => $a->proprietaire?->derniere_app ? Carbon::parse($a->proprietaire->derniere_app)->toIso8601String() : null,
                'inscrit_le' => $a->proprietaire?->created_at?->toIso8601String(),
                'abonnement' => $this->abonnement($a),
            ]),
        ]);
    }

    public function accorder(User $user, Request $request, AbonnementService $service): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'exists:plans,code'],
            'fin' => ['nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['fin.after_or_equal' => 'La fin doit être aujourd’hui ou plus tard.']);

        $abonnement = $service->accorder($user, $data['plan'], isset($data['fin']) ? Carbon::parse($data['fin']) : null, $request->user(), $data['note'] ?? null);

        return response()->json(['message' => "Abonnement de {$user->name} mis à jour.", 'abonnement' => $this->abonnement($abonnement)]);
    }

    public function revoquer(User $user, AbonnementService $service): JsonResponse
    {
        $service->revoquer($user);

        return response()->json(['message' => "Abonnement de {$user->name} révoqué : ses boutiques passent en lecture seule."]);
    }

    public function utilisateurs(Request $request): JsonResponse
    {
        $needle = '%'.mb_strtolower((string) $request->query('recherche', '')).'%';

        $users = User::query()
            ->select('users.*')
            ->addSelect([
                'derniere_app' => DB::table('personal_access_tokens')->selectRaw('MAX(last_used_at)')->whereColumn('tokenable_id', 'users.id'),
                'nb_appareils' => DB::table('appareils')->selectRaw('COUNT(*)')->whereColumn('user_id', 'users.id'),
            ])
            ->with('boutique')
            ->when($request->filled('recherche'), fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(phone) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$needle])))
            ->latest('users.created_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $users->map(fn (User $u) => [
            'id' => $u->id,
            'nom' => $u->name,
            'telephone' => $u->phone,
            'email' => $u->email,
            'boutique' => $u->boutique?->nom,
            'actif' => (bool) $u->is_active,
            'exploitant' => (bool) $u->est_admin_plateforme,
            'appareils' => (int) $u->nb_appareils,
            'derniere_app' => $u->derniere_app ? Carbon::parse($u->derniere_app)->toIso8601String() : null,
            'inscrit_le' => $u->created_at?->toIso8601String(),
        ])]);
    }

    public function basculer(User $user, Request $request, ComptesPlateforme $comptes): JsonResponse
    {
        $user = $comptes->basculer($user, $request->user());

        return response()->json(['actif' => (bool) $user->is_active, 'message' => $user->is_active ? 'Compte réactivé.' : 'Compte désactivé et déconnecté.']);
    }

    public function motDePasse(User $user, Request $request, ComptesPlateforme $comptes): JsonResponse
    {
        return response()->json($comptes->motDePasseProvisoire($user, $request->user()));
    }

    /** Ce que la suppression du compte emporterait (rien n'est touché). */
    public function apercuSuppression(User $user, SuppressionCompte $suppression): JsonResponse
    {
        return response()->json($suppression->apercu($user));
    }

    /** Suppression définitive du compte et de ses boutiques : il faut taper SUPPRIMER. */
    public function supprimerCompte(User $user, Request $request, SuppressionCompte $suppression): JsonResponse
    {
        $request->validate(['confirmation' => ['required', 'in:SUPPRIMER']], ['confirmation.in' => 'Tapez SUPPRIMER pour confirmer.']);
        $resultat = $suppression->supprimer($user, $request->user());

        return response()->json([...$resultat, 'message' => "Compte de {$user->name} supprimé, avec {$resultat['boutiques']} boutique(s) et {$resultat['comptes']} compte(s)."]);
    }

    /** Historique des annonces, et tout ce qu'il faut pour en écrire une (types, audiences, rythmes). */
    public function annonces(): JsonResponse
    {
        return response()->json([
            'types' => Annonce::TYPES,
            'audiences' => Annonce::AUDIENCES,
            'recurrences' => array_filter(Annonce::RECURRENCES, fn ($k) => $k !== '', ARRAY_FILTER_USE_KEY),
            'data' => Annonce::withCount('cibles')->latest('id')->limit(30)->get()->map(fn (Annonce $a) => [
                'id' => $a->id,
                'type' => $a->type,
                'titre' => $a->titre,
                'message' => $a->message,
                'version' => $a->version,
                'lien' => $a->lien,
                'audience' => $a->audience,
                'cibles' => $a->cibles_count,
                'par_email' => (bool) $a->par_email,
                'statut' => $a->statut,
                'programmee_le' => $a->programmee_le?->toIso8601String(),
                'recurrence' => $a->recurrence,
                'derniere_diffusion' => $a->derniere_diffusion?->toIso8601String(),
                'nb_notifies' => (int) $a->nb_notifies,
                'nb_emails' => (int) $a->nb_emails,
            ]),
        ]);
    }

    /** Ce que le formulaire propose d'emblée pour un type (mise à jour : dernière version, lien du Play Store). */
    public function modeleAnnonce(string $type, GestionAnnonces $annonces): JsonResponse
    {
        return response()->json($annonces->modele($type));
    }

    /** Combien de comptes l'annonce toucherait, avant de l'envoyer. */
    public function apercuAnnonce(Request $request, GestionAnnonces $annonces): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(array_keys(Annonce::AUDIENCES))],
            'cibles' => ['array'],
            'cibles.*' => ['string'],
        ]);

        return response()->json(['destinataires' => $annonces->destinatairesPrevus($data['audience'], $data['cibles'] ?? [])]);
    }

    /** Comptes à choisir pour une annonce ciblée. */
    public function comptesAnnonce(Request $request, GestionAnnonces $annonces): JsonResponse
    {
        return response()->json(['data' => $annonces->rechercherComptes((string) $request->query('q'))->map(fn (User $u) => [
            'id' => $u->id, 'nom' => $u->name, 'telephone' => $u->phone, 'email' => $u->email,
        ])]);
    }

    /** Mise à jour, message libre ou campagne ; tout de suite ou programmée (voir GestionAnnonces). */
    public function envoyerAnnonce(Request $request, GestionAnnonces $annonces): JsonResponse
    {
        ['annonce' => $annonce, 'resultat' => $r] = $annonces->creer($request->all() + ['type' => 'message', 'quand' => 'maintenant'], $request->user());

        return response()->json([
            'message' => $r !== null
                ? GestionAnnonces::resume($annonce, $r)
                : "« {$annonce->titre} » programmée le {$annonce->programmee_le->timezone('Africa/Bamako')->format('d/m/Y à H:i')}.",
            'resultat' => $r,
        ], 201);
    }

    public function envoyerAnnonceMaintenant(Annonce $annonce, GestionAnnonces $annonces): JsonResponse
    {
        return response()->json(['message' => GestionAnnonces::resume($annonce, $annonces->envoyerMaintenant($annonce))]);
    }

    public function arreterAnnonce(Annonce $annonce, GestionAnnonces $annonces): JsonResponse
    {
        $annonces->arreter($annonce);

        return response()->json(['message' => 'Envoi programmé arrêté.']);
    }

    /** @return array<string, mixed>|null */
    private function abonnement(?Abonnement $a): ?array
    {
        if ($a === null) {
            return null;
        }

        return [
            'plan' => $a->plan,
            'essai' => $a->estEssai(),
            'en_cours' => $a->estEnCours(),
            'fin' => $a->fin?->toDateString(),
        ];
    }
}
