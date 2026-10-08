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

    // Sans acceptation de cette version, le Service ne s'utilise pas (voir
    // ExigeConditions). Coupé dans les tests des autres écrans seulement.
    'exiger' => true,

    // L'abonnement à vie (prix, période) se tient dans la console : Plans et tarifs.
];
