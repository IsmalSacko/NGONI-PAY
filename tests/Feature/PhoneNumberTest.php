<?php

use App\Enums\Country;
use App\Support\Money\Currencies;
use App\Support\Phone\PhoneNumber;

/**
 * Le numéro est l'identifiant de connexion : sa mise en forme décide qui peut
 * entrer dans l'application. Ces cas verrouillent ce qui, dans une saisie,
 * appartient au numéro et ce qui n'y appartient pas.
 */
it('préfixe un numéro local avec l’indicatif du pays choisi', function () {
    expect(PhoneNumber::normalize('76008201', Country::Mali))->toBe('+22376008201')
        ->and(PhoneNumber::normalize('771234567', Country::Senegal))->toBe('+221771234567')
        ->and(PhoneNumber::normalize('612345678', Country::Cameroon))->toBe('+237612345678');
});

it('ramène toutes les écritures d’un même numéro à une seule', function () {
    foreach (['76008201', '76 00 82 01', '076008201', '22376008201', '+223 76 00 82 01', '0022376008201'] as $saisie) {
        expect(PhoneNumber::normalize($saisie, Country::Mali))->toBe('+22376008201');
    }
});

it('garde le zéro qui appartient au numéro', function () {
    // Dix chiffres en Côte d'Ivoire et au Bénin, mobiles gabonais et congolais,
    // fixes italiens : le zéro de tête y est le premier chiffre de l'abonné, pas
    // un préfixe d'appel. Le retirer donnerait un numéro inexistant, et un
    // commerçant enfermé dehors.
    expect(PhoneNumber::normalize('0708123456', Country::IvoryCoast))->toBe('+2250708123456')
        ->and(PhoneNumber::normalize('07 08 12 34 56', Country::IvoryCoast))->toBe('+2250708123456')
        ->and(PhoneNumber::normalize('0140000000', Country::Benin))->toBe('+2290140000000')
        ->and(PhoneNumber::normalize('06 12 34 56', Country::Gabon))->toBe('+24106123456')
        ->and(PhoneNumber::normalize('06 1234 5678', Country::Italy))->toBe('+390612345678');
});

it('retire le zéro d’appel national là où il en est un', function () {
    expect(PhoneNumber::normalize('076008201', Country::Mali))->toBe('+22376008201')
        ->and(PhoneNumber::normalize('0771234567', Country::Senegal))->toBe('+221771234567');
});

it('met en forme les numéros hors d’Afrique', function () {
    // Un commerçant peut vivre à Paris, à Londres ou à Montréal : son numéro doit
    // s'enregistrer comme les autres, sans quoi il n'a pas de compte.
    expect(PhoneNumber::normalize('06 12 34 56 78', Country::France))->toBe('+33612345678')
        ->and(PhoneNumber::normalize('07911 123456', Country::UnitedKingdom))->toBe('+447911123456')
        ->and(PhoneNumber::normalize('612 34 56 78', Country::Spain))->toBe('+34612345678')
        ->and(PhoneNumber::normalize('0803 123 4567', Country::Nigeria))->toBe('+2348031234567');
});

it('respecte les numéros allemands, dont la longueur varie', function () {
    // Le plan allemand va de six à onze chiffres : aucune longueur de référence
    // ne permet de décider, seul le zéro d'appel se retire.
    expect(PhoneNumber::normalize('0151 23456789', Country::Germany))->toBe('+4915123456789')
        ->and(PhoneNumber::normalize('030 12345678', Country::Germany))->toBe('+493012345678');
});

it('reconnaît le 1 nord-américain comme indicatif et comme préfixe d’appel', function () {
    // « 1 » est à la fois l'indicatif du pays et le chiffre composé avant un
    // appel interurbain : les deux écritures désignent le même abonné.
    expect(PhoneNumber::normalize('(416) 555-1234', Country::Canada))->toBe('+14165551234')
        ->and(PhoneNumber::normalize('1 416 555 1234', Country::Canada))->toBe('+14165551234')
        ->and(PhoneNumber::normalize('416 555 1234', Country::UnitedStates))->toBe('+14165551234');
});

it('retire le 8 de la numérotation russe sans toucher aux indicatifs régionaux', function () {
    // Le 8 ouvre aussi des indicatifs régionaux — Saint-Pétersbourg est le 812.
    // Il ne se retire donc qu'une fois.
    expect(PhoneNumber::normalize('8 912 345 67 89', Country::Russia))->toBe('+79123456789')
        ->and(PhoneNumber::normalize('8 812 333 44 55', Country::Russia))->toBe('+78123334455');
});

it('ne réécrit pas un numéro déjà international avec le pays sélectionné', function () {
    // Un employé joignable à l'étranger doit garder son numéro : le pays choisi
    // dans le formulaire ne le corrige pas.
    expect(PhoneNumber::normalize('+33612345678', Country::Mali))->toBe('+33612345678');
});

it('rend inchangée une saisie qui n’est pas un numéro', function () {
    // Inventer vaut moins bien que ne rien faire : appliqué à une base
    // existante, cela remplacerait des identifiants par des chaînes fabriquées.
    expect(PhoneNumber::normalize('123', Country::Mali))->toBe('123')
        ->and(PhoneNumber::normalize('  ', Country::Mali))->toBe('')
        ->and(PhoneNumber::isValid('123'))->toBeFalse();
});

it('propose les écritures possibles d’un numéro pour retrouver un compte', function () {
    // Les comptes créés avant que le pays ne soit demandé portent parfois un
    // numéro local nu : la connexion doit les retrouver aussi.
    expect(PhoneNumber::candidates('76008201', Country::Mali))
        ->toContain('+22376008201')
        ->toContain('76008201');
});

it('borne les candidats au pays retenu', function () {
    // Sinon le « 76008201 » d'un Malien correspondrait au compte d'un Ivoirien.
    expect(PhoneNumber::candidates('76008201', Country::IvoryCoast))
        ->not->toContain('+22376008201');
});

it('donne à chaque pays son indicatif, sa devise et son drapeau', function () {
    expect(Country::Mali->dialingCode())->toBe('223')
        ->and(Country::Mali->currency())->toBe('XOF')
        ->and(Country::Guinea->currency())->toBe('GNF')
        ->and(Country::Cameroon->currency())->toBe('XAF')
        ->and(Country::France->currency())->toBe('EUR')
        ->and(Country::Nigeria->currency())->toBe('NGN')
        ->and(Country::Canada->dialingCode())->toBe('1')
        ->and(Country::Mali->flag())->toBe('🇲🇱')
        ->and(Country::France->flag())->toBe('🇫🇷');
});

it('décrit chaque pays du catalogue', function () {
    // La table remplace des `match` exhaustifs : c'est ce test qui rattrape un
    // cas ajouté sans sa ligne, plutôt qu'un écran d'inscription servant un pays
    // sans indicatif.
    foreach (Country::cases() as $country) {
        expect($country->label())->not->toBe('')
            ->and($country->dialingCode())->toMatch('/^\d{1,4}$/')
            ->and(Currencies::isSupported($country->currency()))->toBeTrue()
            ->and(mb_strlen($country->flag()))->toBe(2);
    }
});

it('n’a pas deux fois le même pays', function () {
    $codes = array_map(fn (Country $c) => $c->value, Country::cases());

    expect($codes)->toHaveCount(count(array_unique($codes)));
});
