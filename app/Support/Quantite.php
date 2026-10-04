<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\UniteBoutique;
use App\Support\Tenancy\TenantContext;

/**
 * Quantité vendue, stockée, achetée : un entier le plus souvent, parfois une
 * fraction (1,250 kg, un demi-pain) — trois décimales au plus.
 *
 * Un nombre entier part en JSON comme un entier : les applications d'avant la
 * vente au poids le lisent sans rien changer.
 */
final class Quantite
{
    /** Au poids ou à la mesure : les quantités se comptent en fractions. */
    public const MESURES = [
        'kg' => 'kg',
        'g' => 'g',
        'l' => 'L',
        'ml' => 'ml',
        'm' => 'm',
        'cm' => 'cm',
    ];

    /** Au conditionnement : un nom sur le ticket (« 2 sacs »), rien à convertir. */
    public const CONDITIONNEMENTS = [
        'sac' => 'sac',
        'carton' => 'carton',
        'boite' => 'boîte',
        'sachet' => 'sachet',
        'bouteille' => 'bouteille',
        'paquet' => 'paquet',
        'plaquette' => 'plaquette',
        // Unités de marché courantes.
        'tas' => 'tas',
        'botte' => 'botte',
        'bidon' => 'bidon',
        'pot' => 'pot',
        'rouleau' => 'rouleau',
        'lot' => 'lot',
        'douzaine' => 'douzaine',
        'regime' => 'régime',
        'pagne' => 'pagne',
        // Pharmacie : proposés seulement en mode pharmacie.
        'comprime' => 'comprimé',
        'gelule' => 'gélule',
        'ampoule' => 'ampoule',
        'flacon' => 'flacon',
        'tube' => 'tube',
        'suppositoire' => 'suppositoire',
    ];

    /** Unités de vente ; vide = à la pièce. */
    public const UNITES = self::MESURES + self::CONDITIONNEMENTS;

    /** Libellés pour choisir : « Vendu au kilo ». */
    public const LIBELLES = [
        'kg' => 'Kilo (kg)',
        'g' => 'Gramme (g)',
        'l' => 'Litre (L)',
        'ml' => 'Millilitre (ml)',
        'm' => 'Mètre (m)',
        'cm' => 'Centimètre (cm)',
        'sac' => 'Sac',
        'carton' => 'Carton',
        'boite' => 'Boîte',
        'sachet' => 'Sachet',
        'bouteille' => 'Bouteille',
        'paquet' => 'Paquet',
        'plaquette' => 'Plaquette',
        'tas' => 'Tas',
        'botte' => 'Botte',
        'bidon' => 'Bidon',
        'pot' => 'Pot',
        'rouleau' => 'Rouleau',
        'lot' => 'Lot',
        'douzaine' => 'Douzaine',
        'regime' => 'Régime',
        'pagne' => 'Pagne',
        'comprime' => 'Comprimé',
        'gelule' => 'Gélule',
        'ampoule' => 'Ampoule',
        'flacon' => 'Flacon',
        'tube' => 'Tube',
        'suppositoire' => 'Suppositoire',
    ];

    public static function normaliser(mixed $valeur): int|float
    {
        $n = round((float) $valeur, 3);

        return floor($n) === $n && abs($n) < PHP_INT_MAX ? (int) $n : $n;
    }

    /** Saisie du back-office (« 12,5 », « 1 250,75 ») → nombre ; vide → null. */
    public static function lire(?string $saisie): int|float|null
    {
        $texte = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim((string) $saisie));

        return $texte === '' || ! is_numeric($texte) ? null : self::normaliser($texte);
    }

    /** Règle de saisie : un nombre, trois décimales au plus, virgule ou point. */
    public const REGEX_SAISIE = '/^\s*\d[\d\s]*([.,]\d{1,3})?\s*$/';

    /** « 12 », « 12,5 », « 0,25 » — sans zéros inutiles. */
    public static function formater(mixed $valeur, ?string $unite = null): string
    {
        $n = self::normaliser($valeur);
        $texte = is_int($n) ? number_format($n, 0, ',', ' ') : rtrim(rtrim(number_format($n, 3, ',', ' '), '0'), ',');

        if ($unite === null || $unite === '') {
            return $texte;
        }
        // Unité créée par la boutique : son nom, qui s'accorde comme un conditionnement.
        if (! isset(self::UNITES[$unite])) {
            return $texte.' '.($n >= 2 ? (self::plurielsPerso()[$unite] ?? self::pluriel($unite)) : $unite);
        }

        // « 2 sacs », « 1,5 sac », « 3 tas » ; les symboles ne s'accordent pas (2 kg).
        return $texte.' '.(isset(self::CONDITIONNEMENTS[$unite]) && $n >= 2 ? self::pluriel(self::UNITES[$unite]) : self::UNITES[$unite]);
    }

    /** « botte » → « bottes » ; « tas », « prix » ne changent pas. */
    public static function pluriel(string $mot): string
    {
        return preg_match('/[sxz]$/iu', $mot) ? $mot : $mot.'s';
    }

    /** @var array<string, array<string, string>> pluriels irréguliers, par boutique */
    private static array $pluriels = [];

    /** @return array<string, string> */
    private static function plurielsPerso(): array
    {
        $boutique = app(TenantContext::class)->boutiqueId() ?? '';

        return self::$pluriels[$boutique] ??= UniteBoutique::whereNotNull('pluriel')->pluck('pluriel', 'nom')->all();
    }

    public static function oublierPluriels(): void
    {
        self::$pluriels = [];
    }

    /**
     * Règle de validation : une unité de la liste de base, ou une unité créée
     * par la boutique active.
     */
    public static function regle(): \Closure
    {
        return function (string $attribut, mixed $valeur, \Closure $echec): void {
            if ($valeur === null || $valeur === '' || isset(self::UNITES[$valeur])) {
                return;
            }
            if (! is_string($valeur) || ! UniteBoutique::where('nom', $valeur)->exists()) {
                $echec('Cette unité n’existe pas : ajoutez-la d’abord (« Autre unité »).');
            }
        };
    }

    /**
     * Stock d'un article vendu par lot ou au détail, en clair : 53 comprimés
     * (boîte de 16) → « 3 boîtes + 5 comprimés ». Sans palier : la quantité.
     *
     * @param  list<array{unite: string, contenance: int}>|null  $paliers
     */
    public static function formaterStock(mixed $stock, ?string $unite, ?array $paliers): string
    {
        $reste = self::normaliser($stock);
        if (empty($paliers) || $reste <= 0) {
            return self::formater($reste, $unite);
        }
        $morceaux = [];
        foreach (collect($paliers)->sortByDesc('contenance') as $p) {
            $n = (int) floor($reste / max((int) $p['contenance'], 1));
            if ($n > 0) {
                $morceaux[] = self::formater($n, (string) $p['unite']);
                $reste = self::normaliser($reste - $n * (int) $p['contenance']);
            }
        }
        if ($reste > 0 || $morceaux === []) {
            $morceaux[] = $unite === null ? self::formater($reste).' pièce'.($reste >= 2 ? 's' : '') : self::formater($reste, $unite);
        }

        return implode(' + ', $morceaux);
    }

    /** Le symbole à écrire après un prix : « 3 500 F / kg ». */
    public static function symbole(?string $unite): ?string
    {
        return $unite === null || $unite === '' ? null : (self::UNITES[$unite] ?? $unite);
    }
}
