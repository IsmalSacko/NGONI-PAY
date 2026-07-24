<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GrantSubscriptionRequest;
use App\Http\Resources\AdminBusinessResource;
use App\Models\Business;
use App\Models\Subscription;
use Illuminate\Http\Request;

/**
 * Administration des abonnements par un system_admin.
 * Protégé par le middleware `role:system_admin`.
 */
class AdminSubscriptionController extends Controller
{
    /**
     * Liste paginée des business avec propriétaire + abonnement,
     * recherche optionnelle par nom de business / propriétaire / téléphone.
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $needle = '%' . mb_strtolower($search) . '%';

        $businesses = Business::with(['owner', 'subscription'])
            ->when($search !== '', function ($query) use ($needle) {
                // LOWER(col) LIKE ? : insensible à la casse sur MySQL comme sur PostgreSQL.
                $query->where(function ($q) use ($needle) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(phone) LIKE ?', [$needle])
                        ->orWhereHas('owner', function ($ownerQuery) use ($needle) {
                            $ownerQuery->whereRaw('LOWER(name) LIKE ?', [$needle])
                                ->orWhereRaw('LOWER(phone) LIKE ?', [$needle])
                                ->orWhereRaw('LOWER(email) LIKE ?', [$needle]);
                        });
                });
            })
            ->latest()
            ->paginate(20);

        return AdminBusinessResource::collection($businesses)
            ->additional(['summary' => $this->summary()]);
    }

    /**
     * Compteurs globaux : total business (= total abonnements attendus)
     * et répartition par plan.
     */
    private function summary(): array
    {
        $total = Business::count();
        $byPlan = Subscription::selectRaw('plan, COUNT(*) as c')
            ->groupBy('plan')
            ->pluck('c', 'plan');

        $pro = (int) ($byPlan['pro'] ?? 0);
        $basic = (int) ($byPlan['basic'] ?? 0);
        $freePlan = (int) ($byPlan['free'] ?? 0);
        $withSub = $pro + $basic + $freePlan;

        return [
            'total_businesses' => $total,
            'total_subscriptions' => $withSub,
            'pro' => $pro,
            'basic' => $basic,
            // Free explicite + business sans abonnement encore créé.
            'free' => $freePlan + max(0, $total - $withSub),
        ];
    }

    /**
     * Accorde/force manuellement un plan à un business.
     * lifetime=true => ends_at NULL (à vie).
     */
    public function grant(GrantSubscriptionRequest $request, Business $business)
    {
        $data = $request->validated();
        $isLifetime = (bool) ($data['lifetime'] ?? false);

        $startsAt = ! empty($data['starts_at']) ? $data['starts_at'] : now();
        $endsAt = $isLifetime ? null : ($data['ends_at'] ?? null);

        Subscription::updateOrCreate(
            ['business_id' => $business->id],
            [
                'plan' => $data['plan'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'is_active' => true,
                'is_manual' => true,
                'granted_by' => $request->user()->id,
                'admin_note' => $data['admin_note'] ?? null,
            ]
        );

        return (new AdminBusinessResource(
            $business->fresh(['owner', 'subscription'])
        ))->additional([
            'message' => 'Abonnement mis à jour.',
        ]);
    }

    /**
     * Retire l'override manuel : repasse le business en Free expiré
     * et rend la main à la logique automatique d'abonnement.
     */
    public function revoke(Request $request, Business $business)
    {
        $subscription = $business->subscription;

        if ($subscription) {
            $subscription->update([
                'plan' => Subscription::PLAN_FREE,
                'is_active' => true,
                'is_manual' => false,
                'granted_by' => null,
                'admin_note' => null,
                'starts_at' => now(),
                'ends_at' => now(), // Free expiré, aucune prolongation.
            ]);
        }

        return (new AdminBusinessResource(
            $business->fresh(['owner', 'subscription'])
        ))->additional([
            'message' => 'Override retiré.',
        ]);
    }
}
