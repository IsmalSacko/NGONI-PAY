<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Country;
use App\Models\User;
use App\Support\Phone\PhoneNumber;
use Illuminate\Console\Command;

/**
 * Donne (ou retire) l'accès à la console plateforme à un compte existant.
 */
class AdminPlateforme extends Command
{
    protected $signature = 'ecaisse:admin-plateforme {telephone} {--pays=} {--retirer}';

    protected $description = 'Donne ou retire l’accès à la console plateforme (/plateforme) à un compte';

    public function handle(): int
    {
        $pays = Country::tryFrom(strtoupper((string) $this->option('pays'))) ?? Country::default();
        $user = User::whereIn('phone', PhoneNumber::candidates((string) $this->argument('telephone'), $pays))->first();

        if ($user === null) {
            $this->error('Aucun compte avec ce numéro.');

            return self::FAILURE;
        }

        $user->forceFill(['est_admin_plateforme' => ! $this->option('retirer')])->save();

        $this->info($this->option('retirer')
            ? "{$user->name} ({$user->phone}) n’a plus accès à la console plateforme."
            : "{$user->name} ({$user->phone}) accède désormais à /plateforme.");

        return self::SUCCESS;
    }
}
