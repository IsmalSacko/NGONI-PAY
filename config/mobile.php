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

    // Délai entre la publication d'une version sur le Play Store (vue et
    // annoncée) et le moment où MOBILE_MINIMUM_VERSION peut l'imposer : le
    // Play Store la propose à tous les téléphones en quelques jours.
    'delai_version_minimale_jours' => (int) env('MOBILE_DELAI_VERSION_MINIMALE_JOURS', 3),

    // Annonce automatique d'une nouvelle version (ecaisse:annoncer-mise-a-jour) :
    // dès que MOBILE_LATEST_VERSION dépasse la dernière version annoncée, tous
    // les comptes reçoivent une notification (application + push). À changer
    // seulement quand la version est visible sur le Play Store.
    // Texte des nouveautés (facultatif).
    'nouveautes' => env('MOBILE_NOUVEAUTES'),

    // Annonce sans intervention : la CI lit la version en production sur le
    // Play Store (chaque heure) et l'envoie à /api/publication-play avec ce
    // jeton. L'annonce part après ce délai ; 0 : tout de suite.
    'jeton_publication' => env('MOBILE_JETON_PUBLICATION'),
    'delai_annonce_heures' => (int) env('MOBILE_DELAI_ANNONCE_HEURES', 0),
];
