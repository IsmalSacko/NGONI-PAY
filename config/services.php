<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paydunya' => [
    /*
     * Paiement en ligne des abonnements.
     *
     * En pause : l'éditeur n'a pas encore ses clés de production, et une
     * souscription lancée sans elles échouerait chez le commerçant. Toute
     * souscription payante passe donc par une demande validée à la main
     * ({@see \App\Services\SubscriptionRequestService}).
     *
     * Le code du parcours est conservé — {@see \App\Services\Payments\PayDunyaClient::createInvoiceForSubscription}
     * — et se rallume en passant PAYDUNYA_SUBSCRIPTIONS=true, sans rien
     * réécrire. La validation manuelle reste alors possible pour les espèces.
     */
    'subscriptions_enabled' => env('PAYDUNYA_SUBSCRIPTIONS', false),

    'base_url' => env('PAYDUNYA_BASE_URL', 'https://app.paydunya.com/sandbox-api'),
    'master_key' => env('PAYDUNYA_MASTER_KEY'),
    'public_key' => env('PAYDUNYA_PUBLIC_KEY'),
    'private_key' => env('PAYDUNYA_PRIVATE_KEY'),
    'token' => env('PAYDUNYA_TOKEN'),
    'webhook_secret' => env('PAYDUNYA_WEBHOOK_SECRET'),
    ],

];
