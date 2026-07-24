<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\Subscription;
use App\Models\User;
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

Artisan::command('user:make-admin {email}', function (string $email) {
    $user = User::where('email', $email)->first();

    if (! $user) {
        $this->error("Aucun utilisateur avec l'email : {$email}");
        return 1;
    }

    $user->role = User::ROLE_SYSTEM_ADMIN;
    $user->save();

    $this->info("{$user->name} ({$email}) est maintenant system_admin.");
    return 0;
})->purpose('Promouvoir un utilisateur en super-administrateur (system_admin)');

Artisan::command('user:revoke-admin {email}', function (string $email) {
    $user = User::where('email', $email)->first();

    if (! $user) {
        $this->error("Aucun utilisateur avec l'email : {$email}");
        return 1;
    }

    $user->role = User::ROLE_OWNER;
    $user->save();

    $this->info("{$user->name} ({$email}) n'est plus system_admin (repassé owner).");
    return 0;
})->purpose('Retirer le rôle system_admin (repasse owner)');
