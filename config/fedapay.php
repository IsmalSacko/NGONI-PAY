<?php

declare(strict_types=1);

/*
 * FedaPay : paiement en ligne des abonnements hors Côte d'Ivoire (Jèko y
 * reste). Inactif tant que la clé secrète et le secret du webhook ne sont
 * pas dans le .env du serveur.
 */
return [
    /* « sandbox » pour les essais (clés sk_sandbox_…), « live » en production. */
    'environnement' => env('FEDAPAY_ENVIRONNEMENT', 'live'),
    'secret_key' => env('FEDAPAY_SECRET_KEY'),
    'webhook_secret' => env('FEDAPAY_WEBHOOK_SECRET'),

    /*
     * Moyens proposés selon le pays de la boutique (code FedaPay => libellé).
     * La carte bancaire est proposée partout où FedaPay est actif.
     */
    'moyens_par_pays' => [
        'NE' => ['airtel_ne' => 'Airtel Money'],
        'SN' => ['free_sn' => 'Free Money'],
        'BJ' => ['mtn_open' => 'MTN MoMo', 'moov' => 'Moov Money', 'sbin' => 'Celtiis Cash'],
        'TG' => ['moov_tg' => 'Moov Money', 'togocel' => 'Mixx by Yas'],
    ],
    'carte' => ['carte' => 'Carte bancaire (Visa, Mastercard)'],

    /*
     * Commissions du contrat FedaPay (article 6), répercutées sur le commerçant
     * pour que le prix soit reçu en entier.
     */
    'frais_par_moyen' => [
        'mtn_open' => 1.2, 'moov' => 1.2, 'sbin' => 1.2,
        'mtn_ci' => 4.0, 'moov_tg' => 2.5, 'togocel' => 3.5,
        'free_sn' => 2.0, 'airtel_ne' => 4.0, 'carte' => 3.6,
    ],
];
