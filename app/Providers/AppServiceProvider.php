<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Réinitialisation du mot de passe : compteurs dédiés, pour qu'un code à
        // 6 chiffres ne puisse pas être deviné en rafale (par adresse et par numéro).
        RateLimiter::for('password-reset-request', fn (Request $request) => [
            Limit::perMinute(5)->by('pw-request|ip|'.$request->ip()),
            Limit::perHour(10)->by('pw-request|phone|'.preg_replace('/\D+/', '', (string) $request->input('phone'))),
        ]);

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(10)->by('pw-reset|ip|'.$request->ip()),
        ]);
    }
}
