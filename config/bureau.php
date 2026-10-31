<?php

declare(strict_types=1);

/*
 * Versions Windows et macOS de l'application (installateurs construits par la
 * CI du dépôt Flutter, workflow « Versions Windows et macOS »).
 *
 * Les fichiers ne sont pas dans le dépôt (public, et lourds) : ils sont
 * déposés sur le serveur dans storage/app/private/telechargements/ (disque « local ») et servis par
 * /telecharger/windows et /telecharger/mac. Un fichier absent : le bouton ne
 * s'affiche pas. Avec « store », le bouton mène à la boutique du système
 * (mises à jour automatiques, aucun avertissement au premier lancement).
 */
return [
    'version' => env('BUREAU_VERSION', '1.0.0'),

    'dossier' => 'telechargements',

    'systemes' => [
        'windows' => [
            'nom' => 'Windows',
            'fichier' => 'NgoniCaisse-{version}-Windows-Installation.exe',
            'type' => 'application/vnd.microsoft.portable-executable',
            'requis' => 'Windows 10 ou 11, 64 bits',
            'store' => env('BUREAU_WINDOWS_STORE', 'https://apps.microsoft.com/detail/9NV8LH4Q6S3S'),
        ],
        'mac' => [
            'nom' => 'Mac',
            'fichier' => 'NgoniCaisse-{version}-macOS.dmg',
            'type' => 'application/x-apple-diskimage',
            'requis' => 'macOS 10.15 et plus, Intel ou Apple Silicon',
        ],
    ],
];
