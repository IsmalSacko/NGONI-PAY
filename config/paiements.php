<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Paiements en ligne (couche optionnelle)
|--------------------------------------------------------------------------
|
| Les moyens de paiement de la caisse restent déclaratifs : le caissier
| encaisse hors de l'application et l'enregistre. Ce fichier n'active qu'une
| option en plus — faire payer le client en ligne (carte via PayDunya,
| PayPal) et n'enregistrer la vente qu'une fois le paiement confirmé par le
| fournisseur. Sans clés renseignées, rien ne change : le bouton « Payer en
| ligne » n'apparaît simplement pas.
|
| Les clés vivent UNIQUEMENT dans le `.env` du serveur — jamais dans
| l'application Flutter, jamais dans git.
*/

return [
    /*
     * URL publique de l'API, joignable depuis le téléphone du client (retour
     * de paiement) et depuis les serveurs des fournisseurs (webhooks). En
     * local, exposer le serveur (ngrok, cloudflared...) et la renseigner ici ;
     * à défaut, APP_URL.
     */
    'url_publique' => env('PAIEMENTS_URL_PUBLIQUE'),

    /*
     * Quel fournisseur sert quel moyen de paiement de la caisse. Les autres
     * moyens (espèces, mobile money, virement, crédit) restent déclaratifs.
     */
    'moyens' => [
        'carte' => 'paydunya',
        'paypal' => 'paypal',
    ],

    'paypal' => [
        // sandbox | live
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret' => env('PAYPAL_SECRET'),
        // Identifiant du webhook créé dans le tableau de bord PayPal
        // (événements CHECKOUT.ORDER.APPROVED et PAYMENT.CAPTURE.COMPLETED).
        // Facultatif : sans lui, la confirmation passe par la tablette et la
        // page de retour du client, pas par le filet de sécurité serveur.
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        // PayPal ne propose pas le FCFA : on facture en euros (le FCFA est
        // arrimé à l'euro à 655,957 XOF/XAF pour 1 EUR). `taux_fcfa` = nombre
        // de FCFA pour 1 unité de la devise choisie (à adapter si ce n'est
        // plus l'euro).
        'devise' => env('PAYPAL_DEVISE', 'EUR'),
        'taux_fcfa' => (float) env('PAYPAL_TAUX_FCFA', 655.957),
    ],

    'paydunya' => [
        // test | live
        'mode' => env('PAYDUNYA_MODE', 'test'),
        'master_key' => env('PAYDUNYA_MASTER_KEY'),
        'private_key' => env('PAYDUNYA_PRIVATE_KEY'),
        'token' => env('PAYDUNYA_TOKEN'),
        // Canaux proposés sur la page de paiement, séparés par des virgules.
        // `card` : le moyen « Carte bancaire » de la caisse ne doit pas
        // s'enregistrer pour un paiement mobile money fait sur la même page.
        'canaux' => env('PAYDUNYA_CANAUX', 'card'),
    ],
];
