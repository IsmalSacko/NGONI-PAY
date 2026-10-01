<?php

declare(strict_types=1);

/*
 * Accès depuis un navigateur sur une autre adresse : l'application web
 * (Flutter) appelle l'API, et lit les photos d'articles et les logos sous
 * images/ — sans ces en-têtes, le navigateur les bloque et les tuiles de la
 * caisse restent sans photo. Le reste est la valeur par défaut de Laravel.
 */
return [
    'paths' => ['api/*', 'images/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
