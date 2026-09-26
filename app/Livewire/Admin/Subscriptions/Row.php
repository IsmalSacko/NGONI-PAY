<?php

namespace App\Livewire\Admin\Subscriptions;

use App\Models\Business;
use App\Models\Subscription;
use App\Services\SubscriptionRequestService;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Row extends Component
{
    public Business $business;

    public bool $open = false;
    public string $plan = Subscription::PLAN_BASIC;
    public bool $lifetime = false;
    public ?string $endsAt = null;
    public ?string $adminNote = null;

    public ?string $message = null;
    public ?string $messageType = null;

    public function mount(Business $business): void
    {
        $this->business = $business;
        $this->plan = $business->subscription?->plan ?? Subscription::PLAN_BASIC;
    }

    public function toggleOpen(): void
    {
        $this->open = ! $this->open;
        $this->message = null;
    }

    public function grant(): void
    {
        $data = $this->validate([
            'plan' => ['required', Rule::in(Subscription::PLANS)],
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

        // Solde la demande en attente, s'il y en avait une : sans cela elle
        // resterait à instruire alors que le plan est déjà accordé.
        $soldee = app(SubscriptionRequestService::class)->markGrantedManually(
            $this->business,
            auth()->user(),
            $data['plan'],
        );

        $this->business->refresh();
        $this->message = $soldee === null
            ? 'Abonnement mis à jour.'
            : 'Abonnement mis à jour, et la demande en attente a été soldée.';
        $this->messageType = 'status';
        $this->open = false;
    }

    public function revoke(): void
    {
        $this->business->subscription?->expireNow();

        $this->business->refresh();
        $this->message = 'Abonnement révoqué : encaissement bloqué.';
        $this->messageType = 'status';
    }

    public function render()
    {
        return view('livewire.admin.subscriptions.row');
    }
}
