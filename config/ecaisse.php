<?php

declare(strict_types=1);

return [
    /*
     * Pays par défaut proposé à l'inscription — voir App\Enums\Country.
     */
    'default_country' => env('ECAISSE_DEFAULT_COUNTRY', 'ML'),
];
