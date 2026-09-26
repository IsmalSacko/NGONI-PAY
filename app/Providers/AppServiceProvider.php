<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenancy\TenantContext;
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
        // Une seule instance par requête : le middleware SetTenantContext
        // la renseigne, le BoutiqueScope et les services la relisent plus
        // loin dans la même requête.
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Mot de passe oublié : compteurs dédiés (par adresse et par numéro).
        RateLimiter::for('reinit-demande', fn (Request $request) => [
            Limit::perMinute(5)->by('reinit-demande|ip|'.$request->ip()),
            Limit::perHour(10)->by('reinit-demande|tel|'.preg_replace('/\D+/', '', (string) $request->input('telephone'))),
        ]);

        RateLimiter::for('reinit-code', fn (Request $request) => [
            Limit::perMinute(10)->by('reinit-code|ip|'.$request->ip()),
        ]);
    }
}
