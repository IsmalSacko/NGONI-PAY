<?php

return [
    // Destinataire des alertes « nouvelle demande d'abonnement ».
    'request_notification_email' => env('SUBSCRIPTION_REQUEST_EMAIL', 'ismalsacko@yahoo.fr'),

    // Numéro WhatsApp proposé aux commerçants qui préfèrent s'abonner en écrivant
    // directement à l'exploitant plutôt qu'en déposant une demande dans l'app.
    'support_whatsapp' => env('SUBSCRIPTION_SUPPORT_WHATSAPP', '+33605758494'),
];
