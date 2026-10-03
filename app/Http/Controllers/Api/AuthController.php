<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Services\BoutiqueRegistrationService;
use App\Services\ConditionsUtilisation;
use App\Services\ReinitialisationMotDePasse;
use App\Support\Auth\Identification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly BoutiqueRegistrationService $registration) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom_boutique' => ['required', 'string', 'max:255'],
            'pays' => ['required', 'string', 'size:2'],
            'telephone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'nom_utilisateur' => ['required', 'string', 'max:255'],
            'code_parrainage' => ['nullable', 'string', 'max:20'],
            // Case « J'accepte les conditions » : refusée si décochée. Absente, une
            // application d'avant la case : les conditions sont demandées à la connexion.
            'conditions_acceptees' => ['sometimes', 'accepted'],
        ]);

        $result = $this->registration->register([
            'nom' => $data['nom_boutique'],
            'pays' => $data['pays'],
            'telephone' => $data['telephone'],
            'email' => $data['email'] ?? null,
            'password' => $data['password'],
            'nom_utilisateur' => $data['nom_utilisateur'],
            'code_parrainage' => $data['code_parrainage'] ?? null,
        ]);

        if ($request->boolean('conditions_acceptees')) {
            app(ConditionsUtilisation::class)->accepter($result['user'], $request, 'inscription');
        }

        $token = $result['user']->createToken('e-caisse')->plainTextToken;

        return response()->json([
            'boutique' => $result['boutique'],
            'user' => $result['user'],
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telephone' => ['required', 'string', 'max:30'],
            'pays' => ['nullable', 'string', 'size:2'],
            'password' => ['required', 'string'],
        ]);

        $user = Identification::connecter($data['telephone'], $data['pays'] ?? null, $data['password']);

        if ($user === null) {
            Identification::tracerRefus($data['telephone'], $data['pays'] ?? null, $data['password']);

            throw ValidationException::withMessages([
                'telephone' => ['Identifiants incorrects.'],
            ]);
        }

        $token = $user->createToken('e-caisse')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Jeton encore valide : on le révoque. Déjà invalide (révoqué, compte
        // supprimé) : rien à faire, la déconnexion réussit quand même.
        auth('sanctum')->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $boutiqueId = app(TenantContext::class)->boutiqueId();

        // `boutique` : la boutique active de la requête (en-tête X-Boutique),
        // pas forcément celle par défaut du compte.
        $user->setRelation('boutique', Boutique::find($boutiqueId));

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            // Admin d'au moins une boutique : lui seul en ouvre une autre et
            // passe de l'une à l'autre. Un employé ne connaît que la sienne.
            'proprietaire' => $user->peutOuvrirBoutique(),
            // Conditions à (re)accepter : l'application l'exige avant d'ouvrir la caisse.
            'conditions' => app(ConditionsUtilisation::class)->etat($user),
        ]);
    }

    /** « J'accepte » sur l'écran des nouvelles conditions, à la connexion. */
    public function accepterConditions(Request $request): JsonResponse
    {
        $request->validate(['version' => ['required', 'string'], 'conditions_acceptees' => ['accepted']]);
        $conditions = app(ConditionsUtilisation::class);
        // Une version dépassée (texte lu avant une mise à jour) ne vaut pas acceptation.
        if ($request->input('version') !== ConditionsUtilisation::version()) {
            return response()->json(['message' => 'Les conditions ont changé entre-temps : relisez la dernière version.', 'conditions' => $conditions->etat($request->user())], 409);
        }
        $conditions->accepter($request->user(), $request, 'connexion');

        return response()->json(['conditions' => $conditions->etat($request->user()->fresh())]);
    }

    /**
     * Mot de passe oublié, étape 1 : un code est envoyé à l'e-mail du compte.
     *
     * Même réponse que le numéro existe ou non : on ne laisse pas tester des
     * numéros au hasard. Sans e-mail, la réponse renvoie vers le support, qui
     * donne un mot de passe provisoire depuis la console.
     */
    public function motDePasseOublie(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telephone' => ['required', 'string', 'max:30'],
            'pays' => ['nullable', 'string', 'size:2'],
        ]);

        app(ReinitialisationMotDePasse::class)->demander($data['telephone'], $data['pays'] ?? null);

        return response()->json([
            'message' => 'Si ce numéro correspond à un compte avec une adresse e-mail, un code vient '
                .'de vous être envoyé. Sinon, contactez le support sur WhatsApp.',
            'code' => 'CODE_DEMANDE',
            'support_whatsapp' => config('ecaisse.support_whatsapp'),
        ]);
    }

    /**
     * Mot de passe oublié, étape 2 : nouveau mot de passe avec le code reçu.
     * Cinq essais par code ; toutes les sessions sont fermées ensuite.
     */
    public function reinitialiserMotDePasse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telephone' => ['required', 'string', 'max:30'],
            'pays' => ['nullable', 'string', 'size:2'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        app(ReinitialisationMotDePasse::class)->reinitialiser($data['telephone'], $data['pays'] ?? null, $data['code'], $data['password']);

        return response()->json(['message' => 'Mot de passe réinitialisé. Connectez-vous avec le nouveau.']);
    }
}
