<?php

namespace App\Livewire\Admin\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\User;
use App\Services\CampaignMailer;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    /** @var array<int, int> */
    public array $selected = [];

    public bool $isRecurring = false;

    public string $frequency = Campaign::FREQUENCY_WEEKLY;

    protected Campaign $campaign;

    public function mount(): void
    {
        $campaign = $this->campaign();
        $this->isRecurring = $campaign->is_recurring;
        $this->frequency = $campaign->frequency ?? Campaign::FREQUENCY_WEEKLY;
    }

    protected function campaign(): Campaign
    {
        return $this->campaign ??= Campaign::where('key', 'eboutik-announcement')->firstOrFail();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function saveSchedule(CampaignMailer $mailer): void
    {
        $campaign = $this->campaign();

        $campaign->is_recurring = $this->isRecurring;
        $campaign->frequency = $this->frequency;
        $campaign->next_run_at = $this->isRecurring ? $campaign->nextRunFromNow() : null;
        $campaign->save();

        session()->flash('status', $this->isRecurring
            ? "Envoi automatique activé — prochain envoi le {$campaign->next_run_at->format('d/m/Y')}."
            : 'Envoi automatique désactivé.');
    }

    public function sendToAll(CampaignMailer $mailer): void
    {
        $campaign = $this->campaign();
        $users = $mailer->recipientsQuery()->orderBy('id')->get();

        $result = $mailer->sendTo($campaign, $users);
        $campaign->update(['last_run_at' => now()]);

        session()->flash('status', "Envoyé à {$result['sent']} utilisateur(s), {$result['failed']} échec(s).");
        $this->selected = [];
    }

    public function sendToSelected(CampaignMailer $mailer): void
    {
        if (empty($this->selected)) {
            session()->flash('error', 'Aucun utilisateur sélectionné.');

            return;
        }

        $campaign = $this->campaign();
        $users = $mailer->recipientsQuery()->whereIn('id', $this->selected)->get();

        $result = $mailer->sendTo($campaign, $users);
        $campaign->update(['last_run_at' => now()]);

        session()->flash('status', "Envoyé à {$result['sent']} utilisateur(s), {$result['failed']} échec(s).");
        $this->selected = [];
    }

    public function render()
    {
        $campaign = $this->campaign();

        $users = User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->when($this->search, function ($query) {
                $term = '%' . $this->search . '%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE LOWER(?)', [$term])
                        ->orWhereRaw('LOWER(email) LIKE LOWER(?)', [$term]);
                });
            })
            ->with(['campaignSends' => function ($query) use ($campaign) {
                $query->where('campaign_id', $campaign->id)->latest();
            }])
            ->orderByDesc('created_at')
            ->paginate(15);

        $totalRecipients = User::whereNotNull('email')->where('email', '!=', '')->count();

        return view('livewire.admin.campaigns.index', [
            'campaign' => $campaign,
            'users' => $users,
            'totalRecipients' => $totalRecipients,
        ])->layout('components.layouts.admin', ['title' => 'Campagnes']);
    }
}
