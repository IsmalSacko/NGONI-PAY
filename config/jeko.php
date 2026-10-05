<?php

declare(strict_types=1);

/*
 * Jèko : paiement Mobile Money (Côte d'Ivoire) des abonnements. Inactif tant
 * que les quatre valeurs ne sont pas renseignées dans le .env du serveur — la
 * demande avec preuve de paiement reste alors le seul chemin.
 */
return [
    'url' => env('JEKO_URL', 'https://api.jeko.africa'),
    'api_key' => env('JEKO_API_KEY'),
    'api_key_id' => env('JEKO_API_KEY_ID'),
    'store_id' => env('JEKO_STORE_ID'),
    'webhook_secret' => env('JEKO_WEBHOOK_SECRET'),

    /* Pays des boutiques à qui le paiement est proposé : les portefeuilles de Jèko sont ivoiriens. */
    'pays' => ['CI'],

    'moyens' => ['wave' => 'Wave', 'orange' => 'Orange Money', 'mtn' => 'MTN MoMo', 'moov' => 'Moov Money', 'djamo' => 'Djamo'],
];
