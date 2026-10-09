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
     * Une boutique nouvellement créée démarre en mode rodage : ses ventes sont
     * des essais (ESSAI-…), effacés au passage en mode réel, qu'un bandeau
     * rappelle dans l'application.
     */
    'rodage_a_l_inscription' => (bool) env('ECAISSE_RODAGE_A_L_INSCRIPTION', true),
];
