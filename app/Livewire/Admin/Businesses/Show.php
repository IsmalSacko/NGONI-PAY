<?php

namespace App\Livewire\Admin\Businesses;

use App\Models\Business;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

class Show extends Component
{
    use WithPagination;

    public Business $business;

    // Formulaire ajout staff
    public string $staffPhone = '';
    public string $staffRole = 'seller';

    // Formulaire abonnement (grant)
    public string $plan = 'free';
    public bool $lifetime = false;
    public ?string $endsAt = null;
    public ?string $adminNote = null;

    public function mount(Business $business): void
    {
        $this->business = $business;
        $this->plan = $business->subscription?->plan ?? 'free';
    }

    public function toggleActive(): void
    {
        $this->business->update(['is_active' => ! $this->business->is_active]);
    }

    public function addStaff(): void
    {
        $this->validate([
            'staffPhone' => ['required', 'string'],
            'staffRole' => ['required', 'in:manager,seller'],
        ]);

        $user = User::where('phone', $this->staffPhone)->orWhere('email', $this->staffPhone)->first();

        if (! $user) {
            session()->flash('error', 'Aucun utilisateur trouvé avec ce téléphone/email.');
            return;
        }

        if ($this->business->staff()->where('user_id', $user->id)->exists()) {
            session()->flash('error', 'Cet utilisateur est déjà membre.');
            return;
        }

        $this->business->staff()->attach($user->id, ['role' => $this->staffRole]);
        $this->staffPhone = '';
        $this->business->refresh();

        session()->flash('status', 'Membre ajouté.');
    }

    public function removeStaff(int $userId): void
    {
        if ($this->business->owner_id === $userId) {
            session()->flash('error', 'Impossible de retirer le propriétaire.');
            return;
        }

        $this->business->staff()->detach($userId);
        $this->business->refresh();

        session()->flash('status', 'Membre retiré.');
    }

    public function grantSubscription(): void
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
        session()->flash('status', 'Abonnement mis à jour.');
    }

    public function revokeSubscription(): void
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
        session()->flash('status', 'Override retiré.');
    }

    public function cancelPayment(int $paymentId): void
    {
        $payment = Payment::where('business_id', $this->business->id)->findOrFail($paymentId);

        if ($payment->status === 'cancelled') {
            session()->flash('error', 'Ce paiement est déjà annulé.');
            return;
        }

        if ($payment->purpose === 'subscription') {
            session()->flash('error', "Un paiement d'abonnement ne peut pas être annulé ici.");
            return;
        }

        $payment->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => auth()->id(),
            'cancel_reason' => 'Annulé depuis le panneau admin',
        ]);

        session()->flash('status', 'Paiement annulé.');
    }

    public function render()
    {
        $payments = $this->business->payments()
            ->with('client')
            ->latest()
            ->paginate(10, ['*'], 'paymentsPage');

        $clients = $this->business->clients()
            ->latest()
            ->paginate(10, ['*'], 'clientsPage');

        return view('livewire.admin.businesses.show', [
            'staff' => $this->business->staff()->get(),
            'payments' => $payments,
            'clients' => $clients,
        ])->layout('components.layouts.admin', ['title' => $this->business->name]);
    }
}
