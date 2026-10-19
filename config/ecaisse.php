<?php

declare(strict_types=1);

return [
    /*
     * Pays par défaut proposé à l'inscription — voir App\Enums\Country.
     */
    'default_country' => env('ECAISSE_DEFAULT_COUNTRY', 'ML'),

    /*
     * Contact de l'exploitant : WhatsApp proposé aux commerçants (abonnement,
     * mot de passe perdu sans e-mail) et adresse qui reçoit les alertes.
     */
    'support_whatsapp' => env('ECAISSE_SUPPORT_WHATSAPP', '+33605758494'),

    /*
     * Tous les WhatsApp du support, séparés par « ; » : l'application les
     * propose l'un sous l'autre (abonnement à vie, demande d'abonnement). Le
     * premier est aussi support_whatsapp pour les versions déjà installées.
     */
    'supports_whatsapp' => env('ECAISSE_SUPPORTS_WHATSAPP', '+33605758494;+22374988201'),
    /*
     * Numéros où le commerçant dépose le montant de son abonnement, montrés
     * dans la demande d'abonnement de l'application. Format de la variable :
     * « numéro:moyen,moyen;numéro:moyen » (moyens : orange_money, wave, moov).
     */
    'numeros_paiement' => env('ECAISSE_NUMEROS_PAIEMENT', '+22373136789:orange_money,wave;+22374988201:orange_money,wave'),

    'notification_email' => env('ECAISSE_NOTIFICATION_EMAIL', 'ismalsacko@yahoo.fr'),


    /*
     * Boutique de l'exploitant (son identifiant) où chaque abonnement payé en
     * ligne s'enregistre de lui-même comme une vente : le montant, le
     * commerçant en client, le ticket. Vide : rien n'est enregistré.
     */
    'boutique_ventes_abonnements' => env('ECAISSE_BOUTIQUE_VENTES_ABONNEMENTS'),

    // Jeton de vérification de Google Search Console (balise HTML), posé sur la vitrine.
    'google_site_verification' => env('GOOGLE_SITE_VERIFICATION'),
];
