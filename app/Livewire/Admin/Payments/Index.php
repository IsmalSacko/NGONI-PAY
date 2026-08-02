<?php

namespace App\Livewire\Admin\Payments;

use App\Models\Payment;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $status = '';
    public string $method = '';
    public ?string $date = null;

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingMethod(): void
    {
        $this->resetPage();
    }

    public function updatingDate(): void
    {
        $this->resetPage();
    }

    public function cancelPayment(int $paymentId): void
    {
        $payment = Payment::findOrFail($paymentId);

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
        $payments = Payment::with(['business', 'client'])
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->method, fn ($q) => $q->where('method', $this->method))
            ->when($this->date, fn ($q) => $q->whereDate('created_at', $this->date))
            ->latest()
            ->paginate(20);

        return view('livewire.admin.payments.index', ['payments' => $payments])
            ->layout('components.layouts.admin', ['title' => 'Paiements']);
    }
}
