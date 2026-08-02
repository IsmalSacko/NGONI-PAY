<?php

namespace App\Livewire\Admin\Subscriptions;

use App\Models\Business;
use App\Models\Subscription;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

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
            'free' => $freePlan + max(0, $total - $withSub),
        ];
    }

    public function render()
    {
        $needle = '%' . mb_strtolower($this->search) . '%';

        $businesses = Business::with(['owner', 'subscription'])
            ->when($this->search !== '', function ($query) use ($needle) {
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

        return view('livewire.admin.subscriptions.index', [
            'businesses' => $businesses,
            'summary' => $this->summary(),
        ])->layout('components.layouts.admin', ['title' => 'Abonnements']);
    }
}
