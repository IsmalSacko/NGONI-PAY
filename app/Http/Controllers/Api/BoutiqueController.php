<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use App\Services\Fidelite;
use App\Services\Images;
use App\Services\ModeLibre;
use App\Services\ReglagesBoutique;
use App\Services\ReinitialisationBoutique;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Boutiques du compte : celles où il a un rôle, la création d'une nouvelle
 * boutique, et le choix de la boutique par défaut (celle ouverte à la connexion).
 *
 * La boutique de travail d'une requête se choisit par l'en-tête `X-Boutique` ;
 * la boutique par défaut n'est que le repli quand l'en-tête manque.
 */
class BoutiqueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $active = app(TenantContext::class)->boutiqueId();

        $roles = DB::table(config('permission.table_names.model_has_roles'))
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->id)
            ->pluck('roles.name', 'model_has_roles.boutique_id');

        $boutiques = Boutique::whereIn('id', $user->boutiqueIds())->orderBy('nom')->get()
            ->map(fn (Boutique $b) => [
                'id' => $b->id,
                'nom' => $b->nom,
                'pays' => $b->pays,
                'devise' => $b->devise,
                'role' => $roles[$b->id] ?? null,
                'proprietaire' => $b->proprietaire_id === $user->id,
                'active' => $b->id === $active,
                'par_defaut' => $b->id === $user->boutique_id,
            ]);

        return response()->json(['data' => $boutiques]);
    }

    public function store(Request $request, BoutiqueRegistrationService $service): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'pays' => ['nullable', 'string', 'size:2'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'adresse' => ['nullable', 'string', 'max:255'],
        ]);

        $boutique = $service->ajouterBoutique($request->user(), $data);

        return response()->json($boutique, 201);
    }

    /** Réglages de la boutique active (nom, pays, devise, coordonnées). */
    public function update(Request $request, ReglagesBoutique $reglages): JsonResponse
    {
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());

        return response()->json(['data' => $reglages->mettreAJour($boutique, $request->all())]);
    }

    /** Objectif de chiffre d'affaires du mois (null : pas d'objectif). */
    public function objectif(Request $request): JsonResponse
    {
        $data = $request->validate(['objectif_mensuel' => ['present', 'nullable', 'integer', 'min:1', 'max:100000000000']]);
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
        $boutique->forceFill(['objectif_mensuel' => $data['objectif_mensuel']])->save();

        return response()->json(['data' => $boutique->fresh()]);
    }

    /** Vos ventes : au détail, au détail et en gros, en gros uniquement. */
    public function ventes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode_vente' => ['required', Rule::in(Boutique::MODES_VENTE)],
            'vente_commence_en_gros' => ['nullable', 'boolean'],
        ]);
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
        $boutique->forceFill([
            'mode_vente' => $data['mode_vente'],
            'vente_commence_en_gros' => $data['mode_vente'] === 'detail_gros' && ($data['vente_commence_en_gros'] ?? false),
        ])->save();

        return response()->json(['data' => $boutique->fresh()]);
    }

    /**
     * Activité de la boutique : « pharmacie » adapte la caisse (mots, détail,
     * lots, ordonnance), « pressing » aussi (services, express, sans stock).
     */
    public function activite(Request $request): JsonResponse
    {
        $data = $request->validate(['activite' => ['required', Rule::in(Boutique::ACTIVITES)]]);
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
        $boutique->forceFill(['activite' => $data['activite']])->save();
        $boutique->preparerPressing();
        $boutique->preparerRestaurant();

        return response()->json(['data' => $boutique->fresh()]);
    }

    /** Programme de fidélité : seuil d'achats et remise (seuil null : aucun). */
    public function fidelite(Request $request, Fidelite $fidelite): JsonResponse
    {
        $request->validate(['seuil' => ['present', 'nullable', 'integer'], 'remise_pct' => ['nullable', 'integer']]);
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());

        return response()->json(['data' => $fidelite->regler($boutique, [
            'seuil' => $request->filled('seuil') ? $request->integer('seuil') : null,
            'remise_pct' => $request->filled('remise_pct') ? $request->integer('remise_pct') : null,
        ])]);
    }

    /** Logo de la boutique active, imprimé sur les tickets. */
    public function logo(Request $request, Images $images): JsonResponse
    {
        $request->validate(['logo' => Images::REGLES]);
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
        $boutique->update(['logo' => $images->enregistrer($request->file('logo'), 'logos', $boutique->id, $boutique->logo)]);

        return response()->json(['data' => $boutique->fresh()]);
    }

    public function supprimerLogo(Images $images): JsonResponse
    {
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
        $images->supprimer($boutique->logo);
        $boutique->update(['logo' => null]);

        return response()->json(['data' => $boutique->fresh()]);
    }

    /** Boutique ouverte par défaut à la connexion. */
    public function parDefaut(Request $request, string $boutique): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->appartientA($boutique), 403, 'Vous n’avez pas accès à cette boutique.');

        $user->update(['boutique_id' => $boutique]);

        return response()->json(['message' => 'Boutique par défaut mise à jour.']);
    }

    /** Ce que la remise à zéro de la boutique effacerait (rien n'est touché) : le propriétaire seul. */
    public function apercuReinitialisation(Request $request, ReinitialisationBoutique $reinitialisation): JsonResponse
    {
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
        abort_unless(ReinitialisationBoutique::autorise($request->user(), $boutique), 403, 'Seul le propriétaire de la boutique peut la réinitialiser.');

        return response()->json($reinitialisation->apercuBoutique($boutique));
    }

    /** Efface les données d'essai de la boutique : il faut taper REINITIALISER. */
    /**
     * Mode rodage, activé par le propriétaire tant que la boutique n'a pas de
     * vraie vente : ses ventes sont des essais (ESSAI-…), supprimables. On en
     * sort par le passage en mode réel, qui efface tout.
     */
    public function activerRodage(Request $request): JsonResponse
    {
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
        abort_unless(ReinitialisationBoutique::autorise($request->user(), $boutique), 403, 'Seul le propriétaire de la boutique peut passer en mode rodage.');
        if (Vente::where('essai', false)->exists()) {
            throw ValidationException::withMessages(['rodage' => [
                'Votre boutique a déjà de vraies ventes : elles ne peuvent pas devenir des essais. Pour repartir de zéro, utilisez « Repartir de zéro ».',
            ]]);
        }
        $boutique->forceFill(['mode_rodage' => true])->save();

        return response()->json(['data' => $boutique->fresh()]);
    }

    /**
     * Fin du rodage : tout est effacé sauf les comptes (et le catalogue, les
     * fournisseurs s'il le veut), les numéros d'essai repartent à zéro, et la
     * boutique vend pour de vrai.
     */
    public function passerEnModeReel(Request $request, ReinitialisationBoutique $reinitialisation): JsonResponse
    {
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
        abort_unless(ReinitialisationBoutique::autorise($request->user(), $boutique), 403, 'Seul le propriétaire de la boutique peut passer en mode réel.');
        $request->validate([
            'confirmation' => ['required', 'in:MODE REEL'],
            'garder_catalogue' => ['boolean'],
            'garder_fournisseurs' => ['boolean'],
        ], ['confirmation.in' => 'Tapez MODE REEL pour confirmer.']);
        if (! $boutique->mode_rodage) {
            throw ValidationException::withMessages(['rodage' => ['Votre boutique est déjà en mode réel.']]);
        }

        $reinitialisation->reinitialiser($boutique->id, $request->user(), $request->boolean('garder_catalogue', true), $request->boolean('garder_fournisseurs', true));
        $compteurs = array_filter($boutique->fresh()->compteurs_facture ?? [], fn ($serie) => ! str_starts_with((string) $serie, 'ESSAI|'), ARRAY_FILTER_USE_KEY);
        $boutique->forceFill(['mode_rodage' => false, 'compteurs_facture' => $compteurs])->save();

        return response()->json([
            'message' => "{$boutique->nom} est en mode réel : vos essais sont effacés, la prochaine facture sera {$boutique->fresh()->prochaine_facture}.",
            'data' => $boutique->fresh(),
        ]);
    }

    /** Mode libre : le texte à accepter, et l'état de la boutique. */
    public function modeLibre(): JsonResponse
    {
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());

        return response()->json(['data' => [
            'actif' => ModeLibre::actif($boutique),
            'version' => ModeLibre::version(),
            'texte' => ModeLibre::texte(),
            // Déjà actif, mais un texte plus récent est à accepter.
            'a_reaccepter' => $boutique->mode_libre && ! ModeLibre::actif($boutique),
        ]]);
    }

    /**
     * Activer (texte accepté : case cochée et « J'ACCEPTE » tapé) ou quitter le
     * mode libre. Propriétaire seul ; chaque choix est gardé comme preuve.
     */
    public function reglerModeLibre(Request $request, ModeLibre $modeLibre): JsonResponse
    {
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
        abort_unless(ReinitialisationBoutique::autorise($request->user(), $boutique), 403, 'Seul le propriétaire de la boutique peut choisir le mode libre.');
        $data = $request->validate([
            'actif' => ['required', 'boolean'],
            'accepte' => ['exclude_unless:actif,true', 'accepted'],
            'confirmation' => ['exclude_unless:actif,true', 'required', 'in:J\'ACCEPTE'],
            'version' => ['exclude_unless:actif,true', 'required', 'in:'.ModeLibre::version()],
        ], [
            'accepte.accepted' => 'Cochez la case pour accepter les conditions du mode libre.',
            'confirmation.in' => 'Tapez J\'ACCEPTE pour confirmer.',
            'version.in' => 'Le texte a changé : relisez-le avant d’accepter.',
        ]);

        $data['actif'] ? $modeLibre->activer($boutique, $request->user(), $request) : $modeLibre->desactiver($boutique, $request->user(), $request);

        return response()->json([
            'message' => $data['actif'] ? 'Mode libre activé : vos ventes et factures sont sous votre responsabilité.' : 'Mode libre désactivé.',
            'data' => $boutique->fresh(),
        ]);
    }

    /** Journal des ventes supprimées de la boutique, les plus récentes d'abord. */
    public function journalSuppressions(): JsonResponse
    {
        $boutiqueId = app(TenantContext::class)->boutiqueId();

        return response()->json(['data' => DB::table('journal_suppressions')->where('boutique_id', $boutiqueId)
            ->orderByDesc('supprimee_le')->limit(200)
            ->get(['numero_facture', 'total', 'jour', 'par', 'essai', 'supprimee_le', 'lignes'])
            ->map(fn ($l) => [...(array) $l, 'lignes' => json_decode((string) $l->lignes, true), 'essai' => (bool) $l->essai])]);
    }

    public function reinitialiser(Request $request, ReinitialisationBoutique $reinitialisation): JsonResponse
    {
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
        abort_unless(ReinitialisationBoutique::autorise($request->user(), $boutique), 403, 'Seul le propriétaire de la boutique peut la réinitialiser.');
        $request->validate([
            'confirmation' => ['required', 'in:REINITIALISER'],
            'garder_catalogue' => ['boolean'],
            'garder_fournisseurs' => ['boolean'],
            // Tout à zéro, numérotation des factures comprise : seuls les comptes restent.
            'tout_a_zero' => ['boolean'],
        ], ['confirmation.in' => 'Tapez REINITIALISER pour confirmer.']);

        $resultat = $reinitialisation->reinitialiser(
            $boutique->id,
            $request->user(),
            $request->boolean('garder_catalogue', true),
            $request->boolean('garder_fournisseurs', true),
            $request->boolean('tout_a_zero'),
        );

        return response()->json(['message' => "{$resultat['boutique']} est remise à zéro : elle est prête pour vos vraies ventes."]);
    }
}
