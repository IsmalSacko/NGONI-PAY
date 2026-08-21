<?php

return [
    // Dernière version publiée de l'app mobile (ex: "1.0.10").
    // Modifiable sans redéploiement complet : éditer .env puis redémarrer php-fpm.
    'latest_version' => env('MOBILE_LATEST_VERSION', '1.0.0'),

    /*
     * Lien de téléchargement annoncé aux utilisateurs.
     *
     * Il passe par la page publique `/telecharger` plutôt que par le Play Store
     * directement : cette page porte les balises Open Graph, si bien qu'un lien
     * partagé sur WhatsApp affiche le nom, la description et le visuel de
     * l'application au lieu d'une adresse nue.
     */
    // L'identifiant est celui de `android/app/build.gradle.kts` :
    // `com.ismaeldev.ngonipay`, sans souligné. Un identifiant approchant mène à
    // une page introuvable sur le store.
    'store_url' => env('MOBILE_STORE_URL', 'https://play.google.com/store/apps/details?id=com.ismaeldev.ngonipay'),

    // Version en deçà de laquelle la mise à jour n'est plus facultative.
    'minimum_version' => env('MOBILE_MINIMUM_VERSION'),
];
