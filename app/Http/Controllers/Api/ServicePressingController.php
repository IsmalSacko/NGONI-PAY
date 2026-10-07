<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Models\ServicePressing;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Services d'un pressing (« Lavage + repassage », « Repassage seul »…) :
 * chaque habit a un prix par service (voir Produit::prixService()).
 */
class ServicePressingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ServicePressing::orderBy('ordre')->orderBy('nom')->get(['id', 'nom', 'ordre'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->valider($request);
        $service = ServicePressing::create([...$data, 'ordre' => (int) ServicePressing::max('ordre') + 1]);

        return response()->json(['data' => $service->only(['id', 'nom', 'ordre'])], 201);
    }

    public function update(Request $request, ServicePressing $service): JsonResponse
    {
        $service->update($this->valider($request, $service));

        return response()->json(['data' => $service->only(['id', 'nom', 'ordre'])]);
    }

    /** Ses prix disparaissent des habits ; les dépôts déjà faits gardent leur mot. */
    public function destroy(ServicePressing $service): JsonResponse
    {
        DB::transaction(function () use ($service): void {
            Produit::whereNotNull('tarifs')->get()->each(function (Produit $p) use ($service): void {
                $tarifs = collect($p->tarifs)->reject(fn ($t) => $t['service_id'] === $service->id)->values()->all();
                if (count($tarifs) !== count($p->tarifs)) {
                    // Liste vide plutôt que rien : l'habit reste un tarif du pressing, à chiffrer.
                    $p->update(['tarifs' => $tarifs]);
                }
            });
            $service->delete();
        });

        return response()->json(null, 204);
    }

    /** @return array{nom: string} */
    private function valider(Request $request, ?ServicePressing $service = null): array
    {
        $data = $request->validate(['nom' => ['required', 'string', 'max:40']]);
        $data['nom'] = trim($data['nom']);
        $request->merge($data)->validate([
            'nom' => [Rule::unique('services_pressing', 'nom')->where('boutique_id', $this->boutiqueId())->ignore($service?->id)],
        ], ['nom.unique' => 'Ce service existe déjà.']);

        return $data;
    }

    private function boutiqueId(): ?string
    {
        return app(TenantContext::class)->boutiqueId();
    }
}
