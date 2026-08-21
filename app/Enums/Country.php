<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Phone\PhoneNumber;
use LogicException;

/**
 * Pays desservis.
 *
 * L'Afrique d'abord — c'est là que les commerces encaissent — puis les pays où
 * vivent leurs propriétaires et leurs clients : l'Europe, l'Amérique du Nord, le
 * Golfe, les corridors commerciaux. Un commerçant installé à Paris ou à Montréal
 * a un numéro français ou canadien, et un compte à ouvrir comme les autres.
 *
 * Trois informations par pays, et une seule raison à chacune :
 * - l'indicatif, qui préfixe les numéros de téléphone et les rend comparables
 *   d'un pays à l'autre ;
 * - la devise, parce qu'un commerçant de Conakry ne compte pas en francs CFA, ni
 *   celui de Séville en francs guinéens ;
 * - le drapeau, qui n'est pas décoratif : dans une liste de quatre-vingts pays,
 *   il se repère bien plus vite qu'un nom lu de haut en bas.
 *
 * Ces informations vivent dans une table plutôt que dans des `match` : à ce
 * nombre de pays, une table se relit et se corrige ligne par ligne, là où quatre
 * `match` parallèles laissent passer une devise oubliée. Le test
 * `CountryCatalogueTest` vérifie que chaque cas y figure, et que sa devise est
 * connue du catalogue monétaire.
 *
 * L'ordre des cas est celui de la liste présentée : zone franc en tête — la
 * majorité des inscriptions —, puis le reste de l'Afrique, puis l'international.
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

    // CEMAC : franc CFA d'Afrique centrale (XAF).
    case Cameroon = 'CM';
    case Gabon = 'GA';
    case Chad = 'TD';
    case Congo = 'CG';
    case CentralAfrican = 'CF';
    case EquatorialGuinea = 'GQ';

    // Reste de l'Afrique francophone, hors zone FCFA.
    case Guinea = 'GN';
    case DrCongo = 'CD';
    case Mauritania = 'MR';
    case Madagascar = 'MG';
    case Burundi = 'BI';
    case Rwanda = 'RW';
    case Djibouti = 'DJ';
    case Comoros = 'KM';

    // Maghreb et vallée du Nil.
    case Morocco = 'MA';
    case Algeria = 'DZ';
    case Tunisia = 'TN';
    case Libya = 'LY';
    case Egypt = 'EG';
    case Sudan = 'SD';
    case SouthSudan = 'SS';

    // Corne de l'Afrique et Afrique de l'Est.
    case Eritrea = 'ER';
    case Ethiopia = 'ET';
    case Somalia = 'SO';
    case Kenya = 'KE';
    case Uganda = 'UG';
    case Tanzania = 'TZ';

    // Afrique australe.
    case Malawi = 'MW';
    case Zambia = 'ZM';
    case Zimbabwe = 'ZW';
    case Mozambique = 'MZ';
    case Angola = 'AO';
    case Namibia = 'NA';
    case Botswana = 'BW';
    case SouthAfrica = 'ZA';
    case Lesotho = 'LS';
    case Eswatini = 'SZ';

    // Îles.
    case Mauritius = 'MU';
    case Seychelles = 'SC';
    case CapeVerde = 'CV';
    case SaoTome = 'ST';

    // Afrique de l'Ouest anglophone.
    case Gambia = 'GM';
    case Ghana = 'GH';
    case Nigeria = 'NG';
    case SierraLeone = 'SL';
    case Liberia = 'LR';

    // Europe.
    case France = 'FR';
    case Belgium = 'BE';
    case Switzerland = 'CH';
    case Germany = 'DE';
    case Spain = 'ES';
    case Italy = 'IT';
    case Portugal = 'PT';
    case Netherlands = 'NL';
    case Luxembourg = 'LU';
    case UnitedKingdom = 'GB';
    case Ireland = 'IE';
    case Austria = 'AT';
    case Sweden = 'SE';
    case Norway = 'NO';
    case Denmark = 'DK';
    case Finland = 'FI';
    case Poland = 'PL';
    case Czechia = 'CZ';
    case Romania = 'RO';
    case Greece = 'GR';
    case Turkey = 'TR';
    case Russia = 'RU';
    case Ukraine = 'UA';

    // Amériques.
    case Canada = 'CA';
    case UnitedStates = 'US';
    case Brazil = 'BR';

    // Golfe, Levant et corridors commerciaux d'Asie.
    case UnitedArabEmirates = 'AE';
    case SaudiArabia = 'SA';
    case Qatar = 'QA';
    case Kuwait = 'KW';
    case Lebanon = 'LB';
    case China = 'CN';
    case India = 'IN';

    // Océanie.
    case Australia = 'AU';

    /**
     * Nom, indicatif et devise par défaut, par code ISO.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const CATALOGUE = [
        'ML' => ['Mali', '223', 'XOF'],
        'CI' => ['Côte d\'Ivoire', '225', 'XOF'],
        'SN' => ['Sénégal', '221', 'XOF'],
        'BF' => ['Burkina Faso', '226', 'XOF'],
        'BJ' => ['Bénin', '229', 'XOF'],
        'TG' => ['Togo', '228', 'XOF'],
        'NE' => ['Niger', '227', 'XOF'],
        'GW' => ['Guinée-Bissau', '245', 'XOF'],

        'CM' => ['Cameroun', '237', 'XAF'],
        'GA' => ['Gabon', '241', 'XAF'],
        'TD' => ['Tchad', '235', 'XAF'],
        'CG' => ['Congo', '242', 'XAF'],
        'CF' => ['République centrafricaine', '236', 'XAF'],
        'GQ' => ['Guinée équatoriale', '240', 'XAF'],

        'GN' => ['Guinée', '224', 'GNF'],
        'CD' => ['République démocratique du Congo', '243', 'CDF'],
        'MR' => ['Mauritanie', '222', 'MRU'],
        'MG' => ['Madagascar', '261', 'MGA'],
        'BI' => ['Burundi', '257', 'BIF'],
        'RW' => ['Rwanda', '250', 'RWF'],
        'DJ' => ['Djibouti', '253', 'DJF'],
        'KM' => ['Comores', '269', 'KMF'],

        'MA' => ['Maroc', '212', 'MAD'],
        'DZ' => ['Algérie', '213', 'DZD'],
        'TN' => ['Tunisie', '216', 'TND'],
        'LY' => ['Libye', '218', 'LYD'],
        'EG' => ['Égypte', '20', 'EGP'],
        'SD' => ['Soudan', '249', 'SDG'],
        'SS' => ['Soudan du Sud', '211', 'SSP'],

        'ER' => ['Érythrée', '291', 'ERN'],
        'ET' => ['Éthiopie', '251', 'ETB'],
        'SO' => ['Somalie', '252', 'SOS'],
        'KE' => ['Kenya', '254', 'KES'],
        'UG' => ['Ouganda', '256', 'UGX'],
        'TZ' => ['Tanzanie', '255', 'TZS'],

        'MW' => ['Malawi', '265', 'MWK'],
        'ZM' => ['Zambie', '260', 'ZMW'],
        // Le Zimbabwe vit en régime multidevise et compte le plus souvent en
        // dollars : c'est ce qui est proposé, le commerçant restant libre.
        'ZW' => ['Zimbabwe', '263', 'USD'],
        'MZ' => ['Mozambique', '258', 'MZN'],
        'AO' => ['Angola', '244', 'AOA'],
        'NA' => ['Namibie', '264', 'NAD'],
        'BW' => ['Botswana', '267', 'BWP'],
        'ZA' => ['Afrique du Sud', '27', 'ZAR'],
        'LS' => ['Lesotho', '266', 'LSL'],
        'SZ' => ['Eswatini', '268', 'SZL'],

        'MU' => ['Maurice', '230', 'MUR'],
        'SC' => ['Seychelles', '248', 'SCR'],
        'CV' => ['Cap-Vert', '238', 'CVE'],
        'ST' => ['Sao Tomé-et-Principe', '239', 'STN'],

        'GM' => ['Gambie', '220', 'GMD'],
        'GH' => ['Ghana', '233', 'GHS'],
        'NG' => ['Nigeria', '234', 'NGN'],
        'SL' => ['Sierra Leone', '232', 'SLE'],
        'LR' => ['Libéria', '231', 'LRD'],

        'FR' => ['France', '33', 'EUR'],
        'BE' => ['Belgique', '32', 'EUR'],
        'CH' => ['Suisse', '41', 'CHF'],
        'DE' => ['Allemagne', '49', 'EUR'],
        'ES' => ['Espagne', '34', 'EUR'],
        'IT' => ['Italie', '39', 'EUR'],
        'PT' => ['Portugal', '351', 'EUR'],
        'NL' => ['Pays-Bas', '31', 'EUR'],
        'LU' => ['Luxembourg', '352', 'EUR'],
        'GB' => ['Royaume-Uni', '44', 'GBP'],
        'IE' => ['Irlande', '353', 'EUR'],
        'AT' => ['Autriche', '43', 'EUR'],
        'SE' => ['Suède', '46', 'SEK'],
        'NO' => ['Norvège', '47', 'NOK'],
        'DK' => ['Danemark', '45', 'DKK'],
        'FI' => ['Finlande', '358', 'EUR'],
        'PL' => ['Pologne', '48', 'PLN'],
        'CZ' => ['Tchéquie', '420', 'CZK'],
        'RO' => ['Roumanie', '40', 'RON'],
        'GR' => ['Grèce', '30', 'EUR'],
        'TR' => ['Turquie', '90', 'TRY'],
        'RU' => ['Russie', '7', 'RUB'],
        'UA' => ['Ukraine', '380', 'UAH'],

        'CA' => ['Canada', '1', 'CAD'],
        'US' => ['États-Unis', '1', 'USD'],
        'BR' => ['Brésil', '55', 'BRL'],

        'AE' => ['Émirats arabes unis', '971', 'AED'],
        'SA' => ['Arabie saoudite', '966', 'SAR'],
        'QA' => ['Qatar', '974', 'QAR'],
        'KW' => ['Koweït', '965', 'KWD'],
        'LB' => ['Liban', '961', 'LBP'],
        'CN' => ['Chine', '86', 'CNY'],
        'IN' => ['Inde', '91', 'INR'],

        'AU' => ['Australie', '61', 'AUD'],
    ];

    /**
     * Pays dont les numéros d'abonné commencent par un zéro.
     *
     * C'est la seule question que se pose la mise en forme d'un numéro saisi :
     * ce zéro de tête est-il le préfixe d'appel national, ou le premier chiffre
     * du numéro ?
     *
     * Partout ailleurs il est un préfixe d'appel, ou une habitude de frappe, et
     * il se retire. Ici il appartient au numéro :
     * - la Côte d'Ivoire et le Bénin, passés à dix chiffres tous préfixés de 0 ;
     * - le Gabon et le Congo, dont les mobiles s'écrivent 0X XX XX XXX ;
     * - l'Italie, dont les fixes gardent le zéro de leur indicatif régional.
     *
     * Le retirer y donnerait un abonné qui n'existe pas — et un commerçant qui
     * ne peut plus se connecter, puisque son numéro est son identifiant.
     *
     * @var list<string>
     */
    private const LEADING_ZERO = ['CI', 'BJ', 'GA', 'CG', 'IT'];

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function meta(): array
    {
        $meta = self::CATALOGUE[$this->value] ?? null;

        if ($meta === null) {
            // Un cas ajouté sans sa ligne de table : mieux vaut le dire ici que
            // servir un pays sans indicatif à l'écran d'inscription.
            throw new LogicException("Pays {$this->value} absent du catalogue.");
        }

        return $meta;
    }

    public function label(): string
    {
        return $this->meta()[0];
    }

    /**
     * Indicatif téléphonique, sans le « + ».
     */
    public function dialingCode(): string
    {
        return $this->meta()[1];
    }

    /**
     * Devise par défaut du pays.
     *
     * Proposée à la création d'un business, pas imposée : un commerçant peut
     * tenir ses comptes dans une autre monnaie que celle de son pays.
     */
    public function currency(): string
    {
        return $this->meta()[2];
    }

    /**
     * Le zéro de tête d'un numéro local appartient-il au numéro ?
     *
     * Voir {@see self::LEADING_ZERO}.
     */
    public function keepsLeadingZero(): bool
    {
        return in_array($this->value, self::LEADING_ZERO, true);
    }

    /**
     * Chiffre que l'on compose à l'intérieur du pays avant le numéro, ou `null`
     * s'il n'y en a pas.
     *
     * C'est ce préfixe, et lui seul, qu'une saisie locale peut porter en trop :
     * le « 0 » de « 06 12 34 56 78 » en France, le « 8 » de la numérotation
     * russe. Les pays dont les numéros commencent par un zéro n'en ont pas —
     * chez eux, ce zéro est un chiffre du numéro.
     *
     * L'Amérique du Nord compose le 1, qui est aussi son indicatif : une saisie
     * qui le porte est reconnue comme internationale avant d'arriver ici.
     */
    public function trunkPrefix(): ?string
    {
        if ($this->keepsLeadingZero()) {
            return null;
        }

        return $this === self::Russia ? '8' : '0';
    }

    /**
     * Drapeau en émoji, calculé depuis le code ISO.
     *
     * Les deux lettres décalées dans le bloc des indicateurs régionaux Unicode
     * donnent le drapeau : rien à embarquer comme image, et un pays ajouté n'a
     * pas d'illustration à fournir.
     */
    public function flag(): string
    {
        $offset = 0x1F1E6 - ord('A');

        return mb_chr(ord($this->value[0]) + $offset, 'UTF-8')
            .mb_chr(ord($this->value[1]) + $offset, 'UTF-8');
    }

    /**
     * Pays par défaut : celui de l'éditeur, donc de la majorité des inscriptions.
     *
     * Sert aussi à interpréter un numéro local hérité, saisi avant que le pays ne
     * soit demandé ({@see PhoneNumber}).
     */
    public static function default(): self
    {
        $configured = config('app.default_country');

        return is_string($configured)
            ? (self::tryFrom(strtoupper($configured)) ?? self::Mali)
            : self::Mali;
    }

    /**
     * Pays dont l'indicatif correspond, le premier de la liste s'il y en a
     * plusieurs.
     *
     * Un indicatif ne désigne pas toujours un seul pays — « 1 » couvre le Canada
     * et les États-Unis, « 7 » la Russie et le Kazakhstan. Cette méthode ne sert
     * qu'à reconnaître un indicatif comme tel, pas à trancher entre deux pays qui
     * le partagent : le numéro s'écrit de la même façon dans les deux cas.
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

    /**
     * Longueurs possibles d'un indicatif, la plus longue d'abord.
     *
     * Sert à reconnaître un numéro composé en « 00 » : les indicatifs vont de un
     * chiffre (« 1 ») à quatre.
     *
     * @return list<int>
     */
    public static function dialingCodeLengths(): array
    {
        $lengths = [];

        foreach (self::cases() as $country) {
            $lengths[strlen($country->dialingCode())] = true;
        }

        $lengths = array_keys($lengths);
        rsort($lengths);

        return $lengths;
    }
}
