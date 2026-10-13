<?php

declare(strict_types=1);

return [
    // « Ma facture » : factures lues par l'IA, par commerçant et par mois
    // civil, selon son offre en cours. Une lecture qui échoue ne compte pas.
    'lectures_facture_par_mois' => [
        'essai' => (int) env('IA_LECTURES_ESSAI', 1),
        'basic' => (int) env('IA_LECTURES_BASIC', 2),
        'pro' => (int) env('IA_LECTURES_PRO', 5),
    ],
];
