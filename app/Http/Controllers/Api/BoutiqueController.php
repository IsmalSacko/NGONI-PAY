<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\Plan;
use App\Models\Vente;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Services\CatalogueDeDepart;
use App\Services\Fidelite;
use App\Services\Ia\LectureFacture;
use App\Services\Ia\QuotaLectureFacture;
use App\Services\Images;
use App\Services\ModeLibre;
use App\Services\ReglagesBoutique;
use App\Services\ReinitialisationBoutique;
use App\Services\TransfertsArgent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

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
        $boutique->forceFill(['activite' => $data['activite'], 'activite_choisie_le' => $boutique->activite_choisie_le ?? now()])->save();
        $boutique->preparerPressing();
        $boutique->preparerRestaurant();
        app(TransfertsArgent::class)->preparer($boutique);

        return response()->json(['data' => $boutique->fresh()]);
    }

    /**
     * Premier choix du métier, à l'ouverture : la caisse s'y règle d'un coup
     * (grossiste : vente au détail et en gros), et les articles d'exemple qui
     * ne vont pas avec ce métier s'en vont.
     */
    public function demarrage(Request $request, CatalogueDeDepart $depart): JsonResponse
    {
        $data = $request->validate(['activite' => ['required', Rule::in([...Boutique::ACTIVITES, 'grossiste'])]]);
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
        $grossiste = $data['activite'] === 'grossiste';
        $boutique->forceFill([
            'activite' => $grossiste ? 'commerce' : $data['activite'],
            'activite_choisie_le' => now(),
            ...($grossiste ? ['mode_vente' => 'detail_gros'] : []),
        ])->save();
        $boutique->preparerPressing();
        $boutique->preparerRestaurant();
        app(TransfertsArgent::class)->preparer($boutique);
        if (! in_array($boutique->activite, ['commerce'], true)) {
            $depart->retirer();
        }

        return response()->json(['data' => $boutique->fresh()]);
    }

    /** Recevoir (ou non) des commandes de revendeurs, et paraître dans l'annuaire des grossistes. */
    public function recoitCommandes(Request $request): JsonResponse
    {
        $data = $request->validate(['recoit_commandes' => ['required', 'boolean']]);
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
        $boutique->forceFill(['recoit_commandes' => $data['recoit_commandes']])->save();

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

    /**
     * « D'après ma facture » : la facture du commerçant (photo ou PDF) lue par
     * l'IA. Rien n'est enregistré : l'app préremplit les réglages à vérifier.
     */
    public function lireFacture(Request $request, LectureFacture $lecture, QuotaLectureFacture $quota): JsonResponse
    {
        $request->validate(['facture' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:8192']]);
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());

        // Le quota du mois (selon l'offre), compté sur les seules lectures réussies.
        if ($quota->restantes($boutique) <= 0) {
            $etat = $quota->etat($boutique);

            return response()->json([
                'message' => $etat['par_mois'] === 0
                    ? 'L’import de facture n’est pas compris dans votre offre actuelle.'
                    : 'Vous avez déjà importé '.($etat['par_mois'] === 1 ? 'votre facture' : "vos {$etat['par_mois']} factures").' ce mois-ci. '
                        .'Vous pourrez recommencer le '.Carbon::parse($etat['renouvellement'])->translatedFormat('j F').'.',
                'code' => 'QUOTA_LECTURE_FACTURE',
                'quota' => $etat,
            ], 429);
        }

        try {
            $lue = $lecture->lire($request->file('facture'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
        $quota->compter($boutique, (string) $request->user()->id);

        return response()->json(['data' => $lue, 'quota' => $quota->etat($boutique)]);
    }

    /** « Ma facture » : ce qu'il reste d'imports ce mois-ci. */
    public function quotaLectureFacture(QuotaLectureFacture $quota): JsonResponse
    {
        return response()->json(['data' => $quota->etat(Boutique::findOrFail(app(TenantContext::class)->boutiqueId()))]);
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

    /**
     * Le mode rodage n'existe plus : la réinitialisation (Ma boutique) remet
     * une boutique à zéro. Une application d'avant qui le propose encore n'y
     * change rien : la boutique reste en mode réel, sans message.
     */
    public function rodageSupprime(): JsonResponse
    {
        return response()->json(['data' => Boutique::findOrFail(app(TenantContext::class)->boutiqueId())]);
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

        if ($data['actif'] && ! app(AbonnementService::class)->permet($boutique, Plan::MODE_LIBRE)) {
            return response()->json([
                'message' => 'Le mode libre fait partie de l’offre Pro. Passez à l’offre Pro pour l’activer.',
                'code' => 'FONCTIONNALITE_NON_INCLUSE', 'fonctionnalite' => Plan::MODE_LIBRE,
            ], 403);
        }

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

    /** Les numéros de facture changés à la main : ancien, nouveau, qui, quand. */
    public function journalNumeros(): JsonResponse
    {
        return response()->json(['data' => DB::table('journal_numeros_facture')->where('boutique_id', app(TenantContext::class)->boutiqueId())
            ->orderByDesc('le')->limit(200)->get(['ancien', 'nouveau', 'par', 'le'])]);
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
            // Factures et tickets repartent à 1 (sans cela, la numérotation continue).
            'numeros_a_zero' => ['boolean'],
        ], ['confirmation.in' => 'Tapez REINITIALISER pour confirmer.']);

        $resultat = $reinitialisation->reinitialiser(
            $boutique->id,
            $request->user(),
            $request->boolean('garder_catalogue', true),
            $request->boolean('garder_fournisseurs', true),
            $request->boolean('tout_a_zero'),
            $request->boolean('numeros_a_zero'),
        );

        return response()->json(['message' => "{$resultat['boutique']} est remise à zéro : elle est prête pour vos vraies ventes."]);
    }
}
