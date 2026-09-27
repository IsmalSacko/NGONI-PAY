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

    // Annonce automatique d'une nouvelle version (ecaisse:annoncer-mise-a-jour) :
    // dès que MOBILE_LATEST_VERSION dépasse la dernière version annoncée, tous
    // les comptes reçoivent une notification (application + push). À changer
    // seulement quand la version est visible sur le Play Store.
    // Texte des nouveautés (facultatif), et copie par e-mail (non par défaut).
    'nouveautes' => env('MOBILE_NOUVEAUTES'),
    'annonce_par_email' => (bool) env('MOBILE_ANNONCE_PAR_EMAIL', false),

    // Annonce sans intervention : la CI lit la version en production sur le
    // Play Store et l'envoie à /api/publication-play avec ce jeton. L'annonce
    // part après ce délai (le temps que le Play Store la diffuse partout).
    'jeton_publication' => env('MOBILE_JETON_PUBLICATION'),
    'delai_annonce_heures' => (int) env('MOBILE_DELAI_ANNONCE_HEURES', 3),
];
