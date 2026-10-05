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

    /*
     * Frais répercutés sur le commerçant qui paie par Mobile Money, par moyen
     * de paiement (taux du contrat du prestataire) : le prix est majoré pour
     * que, frais déduits, l'offre soit payée en entier. Un moyen absent prend
     * le taux par défaut.
     */
    'frais_pourcentage' => (float) env('JEKO_FRAIS_POURCENTAGE', 1.5),
    'frais_par_moyen' => [
        // Jèko : 1,5 % sur tous les moyens (developer.jeko.africa, tarifs).
        'wave' => 1.5, 'orange' => 1.5, 'mtn' => 1.5, 'moov' => 1.5, 'djamo' => 1.5,
    ],

    /* Sans paiement confirmé après ce délai, la demande est annulée d'elle-même, motif à l'appui. */
    'delai_minutes' => (int) env('JEKO_DELAI_MINUTES', 30),

    'moyens' => ['wave' => 'Wave', 'orange' => 'Orange Money', 'mtn' => 'MTN MoMo', 'moov' => 'Moov Money', 'djamo' => 'Djamo'],
];
