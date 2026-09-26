<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Annonces programmées de la console (campagnes, rappels) : chaque minute.
Artisan::command('ecaisse:diffuser-annonces', function () {
    $n = app(\App\Services\DiffusionAnnonces::class)->diffuserLesEcheances();
    if ($n > 0) {
        $this->info("{$n} annonce(s) diffusée(s).");
    }
})->purpose('Diffuse les annonces programmées arrivées à échéance');

\Illuminate\Support\Facades\Schedule::command('ecaisse:diffuser-annonces')->everyMinute()->withoutOverlapping();
