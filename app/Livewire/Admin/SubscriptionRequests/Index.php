<?php

declare(strict_types=1);

namespace App\Livewire\Admin\SubscriptionRequests;

use App\Enums\SubscriptionRequestStatus;
use App\Models\SubscriptionRequest;
use App\Services\SubscriptionRequestService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * File des demandes d'abonnement.
 *
 * C'est ici que l'accès s'ouvre : un commerçant qui a réglé en espèces ou par
 * mobile money dépose une demande, et rien ne bouge tant qu'elle n'est pas
 * approuvée. La preuve jointe — photo du reçu, capture du SMS — s'ouvre en grand,
 * puisque c'est sur elle que la décision se prend.
 */
class Index extends Component
{
    use WithPagination;

    /** Filtre d'état. Les demandes en attente sont montrées d'abord. */
    public string $status = 'pending';

    public string $search = '';

    /** Demande dont la décision est en cours de saisie. */
    public ?int $decidingId = null;

    /** Motif ou note accompagnant la décision. */
    public string $decisionNote = '';

    /** Preuve affichée en grand, s'il y en a une. */
    public ?string $proofUrl = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function startDeciding(int $id): void
    {
        $this->decidingId = $id;
        $this->decisionNote = '';
    }

    public function cancelDeciding(): void
    {
        $this->decidingId = null;
        $this->decisionNote = '';
    }

    public function showProof(string $url): void
    {
        $this->proofUrl = $url;
    }

    public function hideProof(): void
    {
        $this->proofUrl = null;
    }

    public function approve(int $id, SubscriptionRequestService $service): void
    {
        $this->decide($id, fn (SubscriptionRequest $demande) => $service->approve(
            $demande,
            auth()->user(),
            $this->decisionNote !== '' ? $this->decisionNote : null,
        ), 'Demande approuvée : le plan est actif.');
    }

    public function refuse(int $id, SubscriptionRequestService $service): void
    {
        $this->decide($id, fn (SubscriptionRequest $demande) => $service->refuse(
            $demande,
            auth()->user(),
            $this->decisionNote !== '' ? $this->decisionNote : null,
        ), 'Demande refusée.');
    }

    private function decide(int $id, callable $action, string $message): void
    {
        $demande = SubscriptionRequest::findOrFail($id);

        try {
            $action($demande);
        } catch (ValidationException $e) {
            // Demande déjà tranchée : deux approbations accorderaient deux mois
            // pour un seul paiement.
            session()->flash('error', $e->getMessage());
            $this->cancelDeciding();

            return;
        }

        session()->flash('status', $message);
        $this->cancelDeciding();
    }

    public function render()
    {
        $needle = '%' . mb_strtolower($this->search) . '%';

        $demandes = SubscriptionRequest::query()
            ->with(['business.owner', 'requestedBy', 'decidedBy'])
            ->when(
                $this->status !== 'all',
                fn ($query) => $query->where('status', $this->status),
            )
            ->when($this->search !== '', function ($query) use ($needle) {
                $query->whereHas('business', function ($business) use ($needle) {
                    $business->whereRaw('LOWER(name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(phone) LIKE ?', [$needle]);
                });
            })
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest()
            ->paginate(15);

        // Projections calculées à l'affichage, pas stockées : les jours passent
        // entre le dépôt et la décision, et la date doit rester juste.
        $service = app(SubscriptionRequestService::class);
        $projections = $demandes->getCollection()
            ->filter(fn (SubscriptionRequest $demande) => $demande->status === SubscriptionRequestStatus::Pending)
            ->mapWithKeys(fn (SubscriptionRequest $demande) => [
                $demande->id => $service->projectedEndDate($demande),
            ])
            ->all();

        return view('livewire.admin.subscription-requests.index', [
            'demandes' => $demandes,
            'projections' => $projections,
            'compteurs' => [
                'pending' => SubscriptionRequest::where('status', SubscriptionRequestStatus::Pending)->count(),
                'approved' => SubscriptionRequest::where('status', SubscriptionRequestStatus::Approved)->count(),
                'refused' => SubscriptionRequest::where('status', SubscriptionRequestStatus::Refused)->count(),
            ],
        ])->layout('components.layouts.admin', ['title' => 'Demandes']);
    }
}
