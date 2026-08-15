<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CampaignMailer
{
    public function recipientsQuery()
    {
        return User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '');
    }

    public function usersNotYetSent(Campaign $campaign): Collection
    {
        return $this->recipientsQuery()
            ->whereDoesntHave('campaignSends', function ($query) use ($campaign) {
                $query->where('campaign_id', $campaign->id)
                    ->where('status', CampaignSend::STATUS_SENT);
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{sent: int, failed: int}
     */
    public function sendTo(Campaign $campaign, Collection $users): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                $mailableClass = $campaign->mailable_class;
                Mail::to($user->email)->send(new $mailableClass($user));

                CampaignSend::create([
                    'campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'status' => CampaignSend::STATUS_SENT,
                    'sent_at' => now(),
                ]);
                $sent++;
            } catch (\Throwable $e) {
                Log::error("Campagne {$campaign->key} : échec envoi à {$user->email}", ['error' => $e->getMessage()]);

                CampaignSend::create([
                    'campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'status' => CampaignSend::STATUS_FAILED,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }

            usleep(50_000);
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    public function runDueCampaigns(): void
    {
        Campaign::query()
            ->where('is_recurring', true)
            ->where(function ($query) {
                $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', now());
            })
            ->get()
            ->each(function (Campaign $campaign) {
                $users = $this->usersNotYetSent($campaign);

                if ($users->isNotEmpty()) {
                    $this->sendTo($campaign, $users);
                }

                $campaign->update([
                    'last_run_at' => now(),
                    'next_run_at' => $campaign->nextRunFromNow(),
                ]);
            });
    }
}
