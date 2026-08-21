<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CampaignMailer;
use Carbon\Carbon;

// Envoie les campagnes récurrentes (voir panneau admin > Campagnes) dont la
// date de prochain envoi est passée — ne cible que les utilisateurs qui n'ont
// pas encore reçu la campagne (ex. nouvelles inscriptions).
Schedule::call(fn () => app(CampaignMailer::class)->runDueCampaigns())
    ->hourly()
    ->name('campaigns:run-due')
    ->withoutOverlapping();

// Annonces de mise à jour programmées : vérifiées chaque minute, pour qu'une
// annonce fixée à 8 h partisse à 8 h et non à l'heure ronde suivante.
Schedule::command('announcements:dispatch-due')
    ->everyMinute()
    ->name('announcements:dispatch-due')
    ->withoutOverlapping();

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

Artisan::command('user:make-admin {identifier}', function (string $identifier) {
    // Accepte un email, un téléphone (chiffres, avec ou sans indicatif) ou un id.
    $digits = preg_replace('/\D+/', '', $identifier);

    $user = User::query()
        ->where('email', $identifier)
        ->when($digits !== '', fn ($q) => $q
            ->orWhere('phone', $identifier)
            ->orWhereRaw("REPLACE(REPLACE(phone,'+',''),' ','') LIKE ?", ['%' . $digits])
        )
        ->when(ctype_digit($identifier), fn ($q) => $q->orWhere('id', (int) $identifier))
        ->first();

    if (! $user) {
        $this->error("Aucun utilisateur trouvé pour : {$identifier}");
        return 1;
    }

    $user->role = User::ROLE_SYSTEM_ADMIN;
    $user->save();

    $this->info("#{$user->id} {$user->name} ({$user->email} / {$user->phone}) est maintenant system_admin.");
    return 0;
})->purpose('Promouvoir un utilisateur en super-administrateur (email, téléphone ou id)');

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
