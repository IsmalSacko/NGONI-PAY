<?php

namespace App\Livewire\Admin\Subscriptions;

use App\Models\Business;
use App\Models\Subscription;
use Livewire\Component;

class Row extends Component
{
    public Business $business;

    public bool $open = false;
    public string $plan = 'free';
    public bool $lifetime = false;
    public ?string $endsAt = null;
    public ?string $adminNote = null;

    public ?string $message = null;
    public ?string $messageType = null;

    public function mount(Business $business): void
    {
        $this->business = $business;
        $this->plan = $business->subscription?->plan ?? 'free';
    }

    public function toggleOpen(): void
    {
        $this->open = ! $this->open;
        $this->message = null;
    }

    public function grant(): void
    {
        $data = $this->validate([
            'plan' => ['required', 'in:free,basic,pro'],
            'lifetime' => ['boolean'],
            'endsAt' => ['nullable', 'date'],
            'adminNote' => ['nullable', 'string', 'max:255'],
        ]);

        Subscription::updateOrCreate(
            ['business_id' => $this->business->id],
            [
                'plan' => $data['plan'],
                'starts_at' => now(),
                'ends_at' => $data['lifetime'] ? null : ($data['endsAt'] ?? null),
                'is_active' => true,
                'is_manual' => true,
                'granted_by' => auth()->id(),
                'admin_note' => $data['adminNote'] ?? null,
            ]
        );

        $this->business->refresh();
        $this->message = 'Abonnement mis à jour.';
        $this->messageType = 'status';
        $this->open = false;
    }

    public function revoke(): void
    {
        $subscription = $this->business->subscription;

        if ($subscription) {
            $subscription->update([
                'plan' => Subscription::PLAN_FREE,
                'is_active' => true,
                'is_manual' => false,
                'granted_by' => null,
                'admin_note' => null,
                'starts_at' => now(),
                'ends_at' => now(),
            ]);
        }

        $this->business->refresh();
        $this->plan = 'free';
        $this->message = "Override retiré.";
        $this->messageType = 'status';
    }

    public function render()
    {
        return view('livewire.admin.subscriptions.row');
    }
}
