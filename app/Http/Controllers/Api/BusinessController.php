<?php

namespace App\Http\Controllers\Api;

use App\Models\Business;
use App\Support\Money\Currencies;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Http\Requests\Business\StoreBusinessRequest;
use App\Http\Requests\Business\UpdateBusinessRequest;
use App\Models\Payment;

class BusinessController extends Controller
{
    /**
     * Liste des businesses de l'utilisateur connecté
     */
    public function index(Request $request)
    {
        $businesses = Business::where('owner_id', $request->user()->id)
            ->where('is_active', true)
            ->latest()
            ->paginate(10);

        return BusinessResource::collection($businesses);
    }

    /**
     * Création d'un business
     */
    public function store(StoreBusinessRequest $request)
    {
        $owner = $request->user();

        $business = Business::create([
            'owner_id' => $owner->id,
            'name' => $request->name,
            'type' => $request->type,
            'address' => $request->address,
            'phone' => $request->phone,
            // La devise du pays du propriétaire est le choix par défaut, celui
            // que l'application présélectionne. Elle reste modifiable : un même
            // propriétaire peut tenir un commerce dans une autre monnaie.
            'currency' => Currencies::normalize(
                $request->input('currency'),
                $owner->countryEnum()->currency(),
            ),
            'is_active' => true,
        ]);

        // Le owner est aussi un membre du staff avec le rôle de manager
        $business->staff()->attach(
            $request->user()->id,
            ['role' => 'manager']
        );

        return new BusinessResource($business);
    }

    /**
     * Afficher un business
     */
    public function show(Business $business, Request $request)
    {
        $this->authorizeOwner($business, $request);

        return new BusinessResource($business);
    }

    /**
     * Mise à jour
     */
    public function update(UpdateBusinessRequest $request, Business $business)
    {
        $this->authorizeOwner($business, $request);

        $business->update($request->validated());

        return new BusinessResource($business);
    }

    /**
     * Désactivation logique
     */
    public function destroy(Business $business, Request $request)
    {
        $this->authorizeOwner($business, $request);

        $business->update(['is_active' => false]);

        return response()->json([
            'message' => 'Business désactivé'
        ]);
    }

    /**
     * Liste des businesses désactivés (supprimés) de l'utilisateur connecté
     */
    public function deactivated(Request $request)
    {
        $businesses = Business::where('owner_id', $request->user()->id)
            ->where('is_active', false)
            ->latest()
            ->paginate(10);

        return BusinessResource::collection($businesses);
    }

    /**
     * Réactive un business précédemment désactivé (supprimé)
     */
    public function reactivate(Business $business, Request $request)
    {
        $this->authorizeOwner($business, $request);

        $business->update(['is_active' => true]);

        return new BusinessResource($business);
    }

    /**
     * Statistiques du business
     */

    public function stats(Business $business, Request $request)
    {
        $this->authorizeManager($business, $request);

        return response()->json([
            'data' => [
                // 💰 TOTAUX
                'total_success' => Payment::where('business_id', $business->id)
                    ->where('status', 'success')
                    ->sum('amount'),

                'total_pending' => Payment::where('business_id', $business->id)
                    ->where('status', 'pending')
                    ->sum('amount'),

                'total_failed' => Payment::where('business_id', $business->id)
                    ->where('status', 'failed')
                    ->sum('amount'),

                // 🔢 COUNTS
                'count_success' => Payment::where('business_id', $business->id)
                    ->where('status', 'success')
                    ->count(),

                'count_pending' => Payment::where('business_id', $business->id)
                    ->where('status', 'pending')
                    ->count(),

                'count_failed' => Payment::where('business_id', $business->id)
                    ->where('status', 'failed')
                    ->count(),

                // 📅 PÉRIODES
                'today' => Payment::where('business_id', $business->id)
                    ->whereDate('created_at', today())
                    ->sum('amount'),

                'last_7_days' => Payment::where('business_id', $business->id)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->sum('amount'),

                'last_30_days' => Payment::where('business_id', $business->id)
                    ->where('created_at', '>=', now()->subDays(30))
                    ->sum('amount'),
            ]
        ]);
    }
    /**
     * Statistiques journalières
     */

    public function dailyStats(Business $business, Request $request)
    {
        $this->authorizeManager($business, $request);

        $days = (int) $request->query('days', 7);

        // 1️⃣ Initialiser les jours à 0
        $stats = collect();
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $stats[$date] = 0;
        }

        // 2️⃣ Récupérer les vrais paiements
        $payments = Payment::where('business_id', $business->id)
            ->where('status', 'success')
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('DATE(created_at) as day, SUM(amount) as total')
            ->groupBy('day')
            ->get();

        // 3️⃣ Injecter les montants réels
        foreach ($payments as $payment) {
            $stats[$payment->day] = (float) $payment->total;
        }

        // 4️⃣ Format final pour Flutter
        return response()->json([
            'data' => $stats->map(fn($amount, $day) => [
                'day'    => \Carbon\Carbon::parse($day)->format('d/m'),
                'amount' => $amount,
            ])->values()
        ]);
    }



    /* * 
      *  Statistiques hebdomadaires
    */
    public function weeklyStats(Business $business, Request $request)
    {
        $this->authorizeManager($business, $request);

        $weeks = (int) $request->query('weeks', 4);

        $rows = Payment::where('business_id', $business->id)
            ->where('status', 'success')
            ->where('created_at', '>=', now()->subWeeks($weeks))
            ->selectRaw('YEARWEEK(created_at, 1) as week, SUM(amount) as total')
            ->groupBy('week')
            ->orderBy('week')
            ->get();

        return response()->json([
            'data' => $rows->map(fn($row) => [
                'week'   => 'S' . \Carbon\Carbon::now()->setISODate((int) substr($row->week, 0, 4), (int) substr($row->week, 4, 2))->format('W Y'),
                'amount' => (float) $row->total,
            ])
        ]);
    }


    /**
     * Sécurité : seul le owner
     */
    private function authorizeOwner(Business $business, Request $request): void
    {
        $user = $request->user();
        if ($user->isSystemAdmin()) {
            return;
        }
        if ($business->owner_id !== $user->id) {
            abort(403, 'Accès interdit');
        }
    }

    /**
     * Propriétaire, manager du business, ou super-administrateur.
     */
    private function authorizeManager(Business $business, Request $request): void
    {
        $user = $request->user();

        if ($user->isSystemAdmin() || $business->owner_id === $user->id) {
            return;
        }

        $isManager = $business->staff()
            ->where('user_id', $user->id)
            ->wherePivot('role', 'manager')
            ->exists();

        if (! $isManager) {
            abort(403, 'Accès interdit');
        }
    }
}
