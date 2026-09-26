<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Services\BoutiqueRegistrationService;
use App\Services\ReglagesBoutique;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /** Boutique ouverte par défaut à la connexion. */
    public function parDefaut(Request $request, string $boutique): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->appartientA($boutique), 403, 'Vous n’avez pas accès à cette boutique.');

        $user->update(['boutique_id' => $boutique]);

        return response()->json(['message' => 'Boutique par défaut mise à jour.']);
    }
}
