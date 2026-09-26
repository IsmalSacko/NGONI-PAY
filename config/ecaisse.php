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
    'notification_email' => env('ECAISSE_NOTIFICATION_EMAIL', 'ismalsacko@yahoo.fr'),
];
