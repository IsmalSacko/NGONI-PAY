<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Pays desservis : l'Afrique de l'Ouest, zone FCFA (UEMOA) en tête — e-caisse
 * sert d'abord les boutiques du Mali, de Côte d'Ivoire, du Sénégal...
 *
 * Trois informations par pays, et une seule raison à chacune :
 * - l'indicatif, qui préfixe les numéros de téléphone et les rend comparables
 *   d'un pays à l'autre ;
 * - la devise, pour que le ticket de caisse affiche le bon symbole ;
 * - le drapeau, qui n'est pas décoratif : dans une liste de pays, il se
 *   repère bien plus vite qu'un nom lu de haut en bas.
 */
enum Country: string
{
    // UEMOA : franc CFA d'Afrique de l'Ouest (XOF).
    case Mali = 'ML';
    case IvoryCoast = 'CI';
    case Senegal = 'SN';
    case BurkinaFaso = 'BF';
    case Benin = 'BJ';
    case Togo = 'TG';
    case Niger = 'NE';
    case GuineaBissau = 'GW';

    // Autres pays francophones voisins, hors UEMOA mais toujours en Afrique
    // de l'Ouest ou centrale — utile dès qu'e-caisse dépasse le seul Mali.
    case Guinea = 'GN';
    case Mauritania = 'MR';
    case Cameroon = 'CM';

    public function label(): string
    {
        return match ($this) {
            self::Mali => 'Mali',
            self::IvoryCoast => 'Côte d\'Ivoire',
            self::Senegal => 'Sénégal',
            self::BurkinaFaso => 'Burkina Faso',
            self::Benin => 'Bénin',
            self::Togo => 'Togo',
            self::Niger => 'Niger',
            self::GuineaBissau => 'Guinée-Bissau',
            self::Guinea => 'Guinée',
            self::Mauritania => 'Mauritanie',
            self::Cameroon => 'Cameroun',
        };
    }

    /**
     * Indicatif téléphonique, sans le « + ».
     */
    public function dialingCode(): string
    {
        return match ($this) {
            self::Mali => '223',
            self::IvoryCoast => '225',
            self::Senegal => '221',
            self::BurkinaFaso => '226',
            self::Benin => '229',
            self::Togo => '228',
            self::Niger => '227',
            self::GuineaBissau => '245',
            self::Guinea => '224',
            self::Mauritania => '222',
            self::Cameroon => '237',
        };
    }

    /**
     * Devise par défaut du pays. Proposée à l'inscription, modifiable
     * ensuite par la boutique.
     */
    public function currency(): string
    {
        return match ($this) {
            self::Mali, self::IvoryCoast, self::Senegal, self::BurkinaFaso,
            self::Benin, self::Togo, self::Niger, self::GuineaBissau => 'XOF',

            self::Cameroon => 'XAF',
            self::Guinea => 'GNF',
            self::Mauritania => 'MRU',
        };
    }

    /**
     * Drapeau en émoji, calculé depuis le code ISO : rien à embarquer comme
     * image, et un pays ajouté n'a pas d'illustration à fournir.
     */
    public function flag(): string
    {
        $offset = 0x1F1E6 - ord('A');

        return mb_chr(ord($this->value[0]) + $offset, 'UTF-8')
            .mb_chr(ord($this->value[1]) + $offset, 'UTF-8');
    }

    /**
     * Pays par défaut : celui de l'éditeur, donc de la majorité des
     * inscriptions au lancement.
     */
    public static function default(): self
    {
        $configured = config('ecaisse.default_country');

        return is_string($configured)
            ? (self::tryFrom(strtoupper($configured)) ?? self::Mali)
            : self::Mali;
    }

    /**
     * Pays dont l'indicatif correspond, s'il n'y en a qu'un.
     */
    public static function fromDialingCode(string $code): ?self
    {
        foreach (self::cases() as $country) {
            if ($country->dialingCode() === $code) {
                return $country;
            }
        }

        return null;
    }
}
