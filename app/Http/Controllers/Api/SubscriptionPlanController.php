<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;

/**
 * Catalogue des plans et de leurs tarifs.
 *
 * Route publique, comme celle des pays : l'écran des offres doit pouvoir
 * s'afficher, et un tarif ajusté depuis la console vaut immédiatement pour tous
 * les téléphones — sans publier de nouvelle version.
 */
class SubscriptionPlanController extends Controller
{
    public function index()
    {
        $plans = SubscriptionPlan::query()
            ->with('prices')
            ->active()
            ->ordered()
            ->get();

        return SubscriptionPlanResource::collection($plans);
    }
}
