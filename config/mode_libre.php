<?php

declare(strict_types=1);

/*
 * Mode libre : le propriétaire d'une boutique gère lui-même ses factures et
 * ses ventes (suppression, correction sans limite de date, numérotation), sous
 * sa seule responsabilité. Il l'active en acceptant ce texte ; l'acceptation
 * est gardée comme preuve (qui, quand, d'où, quelle version du texte).
 *
 * Changer le texte : changer aussi `version` — chaque boutique en mode libre
 * devra l'accepter de nouveau.
 */
return [
    'version' => '2026-10-09',

    'texte' => [
        'En activant le mode libre, je peux supprimer et corriger toutes mes ventes et factures, y compris celles des jours passés, et remettre la numérotation de mes factures à zéro quand je le souhaite.',
        'Je reconnais être seul responsable de mes factures, de mes ventes et de leur conservation, notamment au regard de mes obligations fiscales et comptables, en cas de contrôle de l’administration ou de litige avec un client.',
        'Ngoni Caisse (IsmaelDev) fournit l’outil et ne pourra être tenu responsable de l’usage que j’en fais, ni des conséquences d’une suppression ou d’une modification de mes ventes et factures.',
        'Ngoni Caisse garde un journal des ventes supprimées (numéro, montant, date, auteur), consultable dans ma boutique.',
        'Mon acceptation (date, heure, appareil, adresse IP, version de ce texte) est enregistrée comme preuve. Je peux quitter le mode libre à tout moment.',
    ],
];
