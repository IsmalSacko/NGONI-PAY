<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // Clé du compte de service Firebase (notifications push), hors du dépôt.
    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
    ],

    // Lecture d'une facture envoyée par le commerçant (Ma boutique → modèle
    // « D'après ma facture »). Des modèles figés, jamais un alias `-latest`, et
    // un repli sur la même clé : un modèle qui ne répond plus ne bloque rien.
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
        'fallback_model' => env('GEMINI_FALLBACK_MODEL', 'gemini-3.5-flash'),
    ],

    // La Console Ngoni, commune à toutes les applications IsmaelDev.
    'console_ngoni' => [
        'cle' => env('ECAISSE_CLE_CONSOLE', ''),
    ],

];
