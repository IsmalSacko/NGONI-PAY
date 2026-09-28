<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Couleurs des graphiques de la console.
 *
 * Palette catégorielle validée (daltonisme, écart entre voisines) sur le
 * fond blanc des cartes. La couleur suit l'élément, jamais son rang : le
 * Mali reste bleu que la période le mette premier ou quatrième.
 */
final class CouleursGraphiques
{
    public const SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300'];

    /** « Autres », « inconnu » : un gris, hors palette. */
    public const NEUTRE = '#a3adbd';

    private const PLATEFORMES = ['android' => 0, 'ios' => 1, 'web' => 2, 'windows' => 3, 'macos' => 4, 'linux' => 5];

    /** Les pays des commerçants les plus nombreux ; les autres se rangent sous « Autres ». */
    private const PAYS = ['ML' => 0, 'CI' => 1, 'SN' => 2, 'BF' => 3, 'NE' => 4, 'GN' => 5];

    /** Les moyens les plus courants ont leur couleur ; carte, PayPal, virement se partagent le gris. */
    private const MOYENS = ['especes' => 0, 'orange_money' => 1, 'wave' => 2, 'credit_client' => 3, 'moov_money' => 4];

    public static function moyenDePaiement(string $moyen): string
    {
        return isset(self::MOYENS[$moyen]) ? self::SERIES[self::MOYENS[$moyen]] : self::NEUTRE;
    }

    /** Couleur choisie par le commerçant pour sa catégorie (#RRGGBB), sinon le gris. */
    public static function categorie(?string $couleur): string
    {
        return is_string($couleur) && preg_match('/^#[0-9a-fA-F]{6}$/', $couleur) ? $couleur : self::NEUTRE;
    }

    public static function plateforme(string $plateforme): string
    {
        return isset(self::PLATEFORMES[$plateforme]) ? self::SERIES[self::PLATEFORMES[$plateforme]] : self::NEUTRE;
    }

    public static function aSaCouleur(string $pays): bool
    {
        return isset(self::PAYS[$pays]);
    }

    public static function pays(string $pays): string
    {
        return isset(self::PAYS[$pays]) ? self::SERIES[self::PAYS[$pays]] : self::NEUTRE;
    }
}
