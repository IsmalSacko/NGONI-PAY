<?php

namespace App\Livewire\Admin\Businesses;

use App\Models\Business;
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

    public function toggleActive(int $businessId): void
    {
        $business = Business::findOrFail($businessId);
        $business->update(['is_active' => ! $business->is_active]);
    }

    public function render()
    {
        $businesses = Business::with(['owner', 'subscription'])
            ->when($this->search, function ($query) {
                $term = '%' . $this->search . '%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE LOWER(?)', [$term])
                        ->orWhere('phone', 'like', $term)
                        ->orWhereHas('owner', function ($ownerQuery) use ($term) {
                            $ownerQuery->whereRaw('LOWER(name) LIKE LOWER(?)', [$term])
                                ->orWhere('phone', 'like', $term);
                        });
                });
            })
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('livewire.admin.businesses.index', ['businesses' => $businesses])
            ->layout('components.layouts.admin', ['title' => 'Entreprises']);
    }
}
