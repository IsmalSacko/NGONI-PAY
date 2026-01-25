<?php

namespace App\Http\Controllers\Api;

use App\Models\Business;
use App\Models\Subscription;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Http\Requests\Subscription\StoreSubscriptionRequest;
use App\Http\Requests\Subscription\UpdateSubscriptionRequest;

class SubscriptionController extends Controller
{
    public function show(Business $business, Request $request)
    {
        $this->authorizeManager($business, $request);

        return new SubscriptionResource($business->subscription);
    }

    public function store(StoreSubscriptionRequest $request, Business $business)
    {
        $this->authorizeManager($business, $request);

        $subscription = Subscription::create([
            'business_id' => $business->id,
            ...$request->validated(),
            'is_active' => true,
        ]);

        return new SubscriptionResource($subscription);
    }

    public function update(UpdateSubscriptionRequest $request, Business $business, Subscription $subscription)
    {
        $this->authorizeManager($business, $request);

        if ($subscription->business_id !== $business->id) {
            abort(404);
        }

        $subscription->update($request->validated());

        return new SubscriptionResource($subscription);
    }

    private function authorizeManager(Business $business, Request $request): void
    {
        $user = $request->user();

        if ($business->owner_id === $user->id) return;

        $isManager = $business->staff()
            ->where('user_id', $user->id)
            ->wherePivot('role', 'manager')
            ->exists();

        if (! $isManager) abort(403);
    }
}
