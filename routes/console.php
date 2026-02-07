<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\Subscription;
use Carbon\Carbon;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('subscriptions:fix-free-trials', function () {
    $fixed = 0;

    Subscription::where('plan', 'free')
        ->whereNotNull('starts_at')
        ->chunkById(200, function ($subs) use (&$fixed) {
            foreach ($subs as $sub) {
                $startsAt = Carbon::parse($sub->starts_at);
                $maxEnd = $startsAt->copy()->addDays(7);

                if ($sub->ends_at === null || Carbon::parse($sub->ends_at)->gt($maxEnd)) {
                    $sub->ends_at = $maxEnd;
                    $sub->save();
                    $fixed++;
                }
            }
        });

    $this->info("Free trial subscriptions fixed: {$fixed}");
})->purpose('Fix free plan trials to 7 days from starts_at');
