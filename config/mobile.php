<?php

declare(strict_types=1);

return [
    // Dernière version publiée de l'application (ex. « 2.0.0 »).
    'latest_version' => env('MOBILE_LATEST_VERSION', '2.0.0'),

    // Même fiche Play Store que Ngoni Pay : e-caisse en est la mise à jour.
    'store_url' => env('MOBILE_STORE_URL', 'https://play.google.com/store/apps/details?id=com.ismaeldev.ngonipay'),

    // En deçà, la mise à jour est obligatoire : les applications Ngoni Pay 1.x
    // parlent à une API qui n'existe plus.
    'minimum_version' => env('MOBILE_MINIMUM_VERSION', '2.0.0'),
];
