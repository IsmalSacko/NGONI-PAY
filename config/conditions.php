<?php

declare(strict_types=1);

/*
 * Conditions d'utilisation de Ngoni Caisse.
 *
 * `version` change à chaque modification des conditions (CGU/CGV ou
 * politique de confidentialité) : chaque compte doit alors les accepter de
 * nouveau, à sa prochaine connexion. Les textes sont dans
 * resources/views/juridique/.
 */
return [
    // Date de publication, suivie d'un numéro si le texte change le même jour.
    'version' => '2026-10-02.2',

    // Offre de lancement « abonnement à vie » : souscription possible entre ces deux dates.
    'a_vie' => [
        'debut' => '2026-09-01',
        'fin' => '2027-02-28',
        'basic' => 100000,
        'pro' => 250000,
    ],
];
