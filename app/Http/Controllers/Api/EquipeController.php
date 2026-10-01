<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\User;
use App\Services\EquipeService;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Équipe de la boutique active, depuis l'application. Consultable par l'admin
 * et le gérant ; seul l'admin ajoute, change un rôle ou des droits, ou retire
 * un membre.
 */
class EquipeController extends Controller
{
    public function __construct(private readonly EquipeService $equipe) {}

    public function index(Request $request): JsonResponse
    {
        $boutique = $this->boutique();

        return response()->json([
            'data' => $this->equipe->membres($boutique)->map(fn (User $u) => $this->json($u, $boutique, $request->user()))->values(),
            // Les droits que l'on coche par membre, et ceux de chaque rôle par défaut.
            'droits' => collect(Permissions::DROITS)->map(fn (array $d, string $cle) => [
                'cle' => $cle, 'libelle' => $d['libelle'], 'explication' => $d['explication'],
            ])->values(),
            'droits_par_defaut' => Permissions::DROITS_PAR_DEFAUT,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'telephone' => ['required', 'string', 'max:30'],
            'role' => ['required', Rule::in(EquipeService::ROLES)],
            'password' => ['nullable', 'string', 'min:8'],
            'droits' => ['sometimes', 'array'],
            'droits.*' => ['string', Rule::in(array_keys(Permissions::DROITS))],
        ]);

        $boutique = $this->boutique();
        $resultat = $this->equipe->ajouter($boutique, $data['nom'], $data['telephone'], $data['role'], $data['password'] ?? null, $data['droits'] ?? null);

        return response()->json([
            'data' => $this->json($resultat['user']->load('roles'), $boutique, $request->user()),
            'cree' => $resultat['cree'],
            'mot_de_passe_provisoire' => $resultat['mot_de_passe'],
        ], 201);
    }

    public function update(Request $request, string $membre): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(EquipeService::ROLES)],
            'droits' => ['sometimes', 'array'],
            'droits.*' => ['string', Rule::in(array_keys(Permissions::DROITS))],
        ]);
        $boutique = $this->boutique();

        $user = $this->equipe->changerRole($boutique, $request->user(), $membre, $data['role'], $data['droits'] ?? null);

        return response()->json(['data' => $this->json($user, $boutique, $request->user())]);
    }

    public function destroy(Request $request, string $membre): JsonResponse
    {
        $this->equipe->retirer($this->boutique(), $request->user(), $membre);

        return response()->json(status: 204);
    }

    private function boutique(): Boutique
    {
        return Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
    }

    /** @return array<string, mixed> */
    private function json(User $u, Boutique $boutique, User $moi): array
    {
        return [
            'id' => $u->id,
            'nom' => $u->name,
            'telephone' => $u->phone,
            'role' => $u->roles->first()?->name,
            'est_actif' => (bool) $u->is_active,
            'est_proprietaire' => $u->id === $boutique->proprietaire_id,
            'est_moi' => $u->id === $moi->id,
            'droits' => $this->equipe->droitsDans($boutique, $u),
        ];
    }
}
