<?php

declare(strict_types=1);

/*
 * pawaPay : paiement Mobile Money des abonnements au Sénégal, au Burkina Faso
 * et au Bénin (la Côte d'Ivoire reste à Jèko). Inactif tant que le jeton n'est
 * pas dans le .env du serveur.
 */
return [
    /* « sandbox » pour les essais, « live » en production. */
    'environnement' => env('PAWAPAY_ENVIRONNEMENT', 'live'),
    'token' => env('PAWAPAY_TOKEN'),
    /* Page de retour après paiement ; vide : celle de ce serveur. pawaPay exige une adresse publique en https (en local : celle de la prod). */
    'url_retour' => env('PAWAPAY_URL_RETOUR'),

    /*
     * Pays de la boutique (ISO 2) => code pawaPay (ISO 3), devise, et les
     * opérateurs affichés. La page de pawaPay laisse choisir l'opérateur.
     * Seulement ce que le compte a d'actif (GET /v2/active-conf, vérifié le
     * 2026-10-09) : ni Wave au Sénégal, ni le Burkina Faso.
     */
    'pays' => [
        'SN' => ['code' => 'SEN', 'devise' => 'XOF', 'moyens' => ['orange_sen' => 'Orange Money', 'free_sen' => 'Free Money']],
        'BJ' => ['code' => 'BEN', 'devise' => 'XOF', 'moyens' => ['mtn_momo_ben' => 'MTN MoMo', 'moov_ben' => 'Moov Money']],
    ],

    /* Commission du contrat pawaPay (%), répercutée sur le commerçant. */
    'frais_pourcentage' => (float) env('PAWAPAY_FRAIS_POURCENTAGE', 3.0),
];
