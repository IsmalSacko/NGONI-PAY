<?php

namespace App\Livewire\Admin;

use App\Models\Business;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $stats = [
            'total_users' => User::count(),
            'new_users_today' => User::whereDate('created_at', today())->count(),
            'active_businesses' => Business::where('is_active', true)->count(),
            'total_businesses' => Business::count(),
            'payments_today_amount' => Payment::whereDate('created_at', today())
                ->where('status', 'success')
                ->sum('amount'),
            'payments_today_count' => Payment::whereDate('created_at', today())
                ->where('status', 'success')
                ->count(),
        ];

        $plans = Subscription::selectRaw('plan, count(*) as total')
            ->groupBy('plan')
            ->pluck('total', 'plan');

        return view('livewire.admin.dashboard', [
            'stats' => $stats,
            'plans' => $plans,
        ])->layout('components.layouts.admin', ['title' => 'Tableau de bord']);
    }
}
