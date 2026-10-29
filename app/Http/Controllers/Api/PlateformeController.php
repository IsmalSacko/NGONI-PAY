<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Abonnement;
use App\Models\Annonce;
use App\Models\Boutique;
use App\Models\Plan;
use App\Models\User;
use App\Models\Vente;
use App\Services\ComptesPlateforme;
use App\Services\ConditionsUtilisation;
use App\Services\Console\ComptesConsole;
use App\Services\Console\UtilisateursConsole;
use App\Services\GestionAnnonces;
use App\Services\Plateforme\Activite;
use App\Services\ReinitialisationBoutique;
use App\Services\SuppressionCompte;
use App\Support\Periode;
use App\Support\WhatsApp;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Console de l'exploitant dans l'application : les mêmes gestes que la
 * console web (/plateforme), sur les mêmes services. Les abonnements
 * (demandes, paiements, formules, accords) se gèrent dans la Console Ngoni.
 */
class PlateformeController extends Controller
{
    /**
     * Chiffres de la plateforme. Les abonnements y sont comptés (lecture
     * seule, mêmes statuts que la page Comptes) ; ils se gèrent dans la
     * Console Ngoni.
     */
    public function tableau(ComptesConsole $console): JsonResponse
    {
        $ventesDuJour = Vente::withoutBoutiqueScope()->valides()->whereDate('created_at', today());
        $compteurs = $console->compteurs();

        return response()->json([
            'comptes' => Abonnement::avecCompte()->count(),
            'essais' => $compteurs['essai'],
            'payants' => $compteurs['payants'],
            'fin_proche' => $compteurs['fin_proche'],
            'expires' => $compteurs['expires'],
            // Lu par les applications installées avant la page Comptes par statut.
            'actifs' => $compteurs['essai'] + $compteurs['payants'],
            'boutiques' => Boutique::count(),
            'utilisateurs' => User::count(),
            'inscriptions_7j' => User::where('created_at', '>=', now()->subDays(7))->whereNotNull('boutique_id')->count(),
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

    public function comptes(Request $request, ComptesConsole $console): JsonResponse
    {
        $requete = $console->requete([
            'recherche' => $request->query('recherche'),
            // « filtre » : nom du paramètre dans les applications déjà installées.
            'statut' => $request->query('statut', $request->query('filtre')),
            'plan' => $request->query('plan'),
            'pays' => $request->query('pays'),
            'activite' => $request->query('activite'),
            'usage' => $request->query('usage'),
            'tri' => $request->query('tri'),
        ])->with(['proprietaire.parrain:id,name', 'proprietaire.boutiquesPossedees' => fn ($q) => $q
            ->withCount(['ventes as nb_ventes' => fn ($v) => $v->withoutGlobalScopes()->where('statut', 'validee')])
            ->withMax(['ventes as derniere_vente' => fn ($v) => $v->withoutGlobalScopes()->where('statut', 'validee')], 'created_at')
            ->withSum(['ventes as total_30j' => fn ($v) => $v->withoutGlobalScopes()->where('statut', 'validee')->where('created_at', '>=', now()->subDays(30))], 'total')]);

        // Page par page (50) ; sans « page », les 100 premiers comme avant.
        $page = $request->filled('page') ? $requete->paginate(50) : null;
        $abonnements = $page?->getCollection() ?? $requete->limit(100)->get();

        return response()->json([
            'plans' => Plan::ordonnes()->get(['code', 'nom']),
            'data' => $abonnements->map(fn (Abonnement $a) => [
                'user_id' => $a->user_id,
                'nom' => $a->proprietaire?->name,
                'telephone' => $a->proprietaire?->phone,
                'email' => $a->proprietaire?->email,
                'whatsapp' => WhatsApp::link($a->proprietaire?->phone),
                'boutiques' => $a->proprietaire?->boutiquesPossedees->pluck('nom')->values() ?? [],
                'boutiques_detail' => $a->proprietaire?->boutiquesPossedees->map(fn (Boutique $b) => [
                    'id' => $b->id,
                    'nom' => $b->nom,
                    'devise' => $b->devise,
                    'cree_le' => $b->created_at?->toIso8601String(),
                    'ventes' => (int) $b->nb_ventes,
                    'derniere_vente' => $b->derniere_vente ? Carbon::parse($b->derniere_vente)->toIso8601String() : null,
                    'total_30j' => (int) $b->total_30j,
                ])->values() ?? [],
                'parrain' => $a->proprietaire?->parrain?->name,
                'derniere_app' => $a->derniere_app ? Carbon::parse($a->derniere_app)->toIso8601String() : null,
                'inscrit_le' => $a->proprietaire?->created_at?->toIso8601String(),
                'ventes' => (int) $a->nb_ventes,
                'ca_30j' => (int) $a->ca_30j,
                'abonnement' => $this->abonnement($a),
            ]),
            'total' => $page?->total() ?? $abonnements->count(),
            'page' => $page?->currentPage() ?? 1,
            'pages' => $page?->lastPage() ?? 1,
            'compteurs' => $console->compteurs(),
            'filtres' => [
                'statuts' => ComptesConsole::STATUTS,
                'usages' => ComptesConsole::USAGES,
                'tris' => ComptesConsole::TRIS,
                'pays' => $console->pays(),
                'activites' => array_combine(Boutique::ACTIVITES, array_map('ucfirst', Boutique::ACTIVITES)),
            ],
        ]);
    }

    public function utilisateurs(Request $request, UtilisateursConsole $console): JsonResponse
    {
        $requete = $console->requete([
            'recherche' => $request->query('recherche'),
            'role' => $request->query('role'),
            'etat' => $request->query('etat'),
            'pays' => $request->query('pays'),
            'appli' => $request->query('appli'),
            'conditions' => $request->query('conditions'),
            'tri' => $request->query('tri'),
        ]);
        $page = $request->filled('page') ? $requete->paginate(50) : null;
        $users = $page?->getCollection() ?? $requete->limit(100)->get();

        $conditions = app(ConditionsUtilisation::class)->dernieres($users->pluck('id')->map(fn ($id) => (string) $id)->all());

        return response()->json(['data' => $users->map(fn (User $u) => [
            'id' => $u->id,
            'nom' => $u->name,
            'telephone' => $u->phone,
            'email' => $u->email,
            'boutique' => $u->boutique?->nom,
            'actif' => (bool) $u->is_active,
            'exploitant' => (bool) $u->est_admin_plateforme,
            'appareils' => (int) $u->nb_appareils,
            'ventes' => (int) $u->nb_ventes,
            'whatsapp' => WhatsApp::link($u->phone),
            // Dernière acceptation des conditions (null : jamais acceptées).
            'conditions' => $conditions[(string) $u->id] ?? null,
            'derniere_app' => $u->derniere_app ? Carbon::parse($u->derniere_app)->toIso8601String() : null,
            'inscrit_le' => $u->created_at?->toIso8601String(),
        ]),
            'total' => $page?->total() ?? $users->count(),
            'page' => $page?->currentPage() ?? 1,
            'pages' => $page?->lastPage() ?? 1,
            'filtres' => [
                'roles' => UtilisateursConsole::ROLES,
                'etats' => UtilisateursConsole::ETATS,
                'applis' => UtilisateursConsole::APPLIS,
                'conditions' => UtilisateursConsole::CONDITIONS,
                'tris' => UtilisateursConsole::TRIS,
                'pays' => app(ComptesConsole::class)->pays(),
            ],
        ]);
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

    /** Boutiques du compte et ce que leur remise à zéro effacerait (rien n'est touché). */
    public function apercuReinitialisation(User $user, ReinitialisationBoutique $reinitialisation): JsonResponse
    {
        return response()->json($reinitialisation->apercu($user));
    }

    /** Remise à zéro des données d'essai d'une boutique : il faut taper REINITIALISER. */
    public function reinitialiserBoutique(string $boutique, Request $request, ReinitialisationBoutique $reinitialisation): JsonResponse
    {
        $request->validate([
            'confirmation' => ['required', 'in:REINITIALISER'],
            'garder_catalogue' => ['boolean'],
            'garder_fournisseurs' => ['boolean'],
        ], ['confirmation.in' => 'Tapez REINITIALISER pour confirmer.']);
        $resultat = $reinitialisation->reinitialiser(
            $boutique,
            $request->user(),
            $request->boolean('garder_catalogue', true),
            $request->boolean('garder_fournisseurs', true),
        );

        return response()->json([...$resultat, 'message' => "{$resultat['boutique']} est remise à zéro."]);
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
                // Les annonces ne partent plus par e-mail ; gardé pour les anciennes versions de l'application.
                'par_email' => false,
                'statut' => $a->statut,
                'programmee_le' => $a->programmee_le?->toIso8601String(),
                'recurrence' => $a->recurrence,
                'derniere_diffusion' => $a->derniere_diffusion?->toIso8601String(),
                'nb_notifies' => (int) $a->nb_notifies,
                'nb_emails' => (int) $a->nb_emails,
                'nb_echecs' => (int) $a->nb_echecs,
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

        return response()->json(['destinataires' => $annonces->destinatairesPrevus($data['audience'], $data['cibles'] ?? [], $request->input('type'))]);
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
        ['annonce' => $annonce, 'lancee' => $lancee] = $annonces->creer($request->all() + ['type' => 'message', 'quand' => 'maintenant'], $request->user());

        return response()->json([
            'message' => $lancee
                ? $annonces->resume($annonce)
                : "« {$annonce->titre} » programmée le {$annonce->programmee_le->timezone('Africa/Bamako')->format('d/m/Y à H:i')}.",
            'lancee' => $lancee,
        ], 201);
    }

    public function envoyerAnnonceMaintenant(Annonce $annonce, GestionAnnonces $annonces): JsonResponse
    {
        $annonces->envoyerMaintenant($annonce);

        return response()->json(['message' => $annonces->resume($annonce)]);
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
            // Comme la console web : accordé à la main, et jours de parrainage en attente.
            'manuel' => (bool) $a->est_manuel,
            'jours_offerts' => (int) $a->jours_offerts,
        ];
    }
}
