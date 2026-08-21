<?php

namespace App\Services;

use App\Enums\Country;
use App\Support\Phone\PhoneNumber;

/**
 * Mise au format des numéros, à l'échelle de l'application.
 *
 * Ne fait plus le travail lui-même : il est confié à {@see PhoneNumber}, qui
 * connaît les indicatifs de tous les pays desservis. Ce service reste le point
 * d'entrée injecté dans les contrôleurs et les FormRequest.
 *
 * L'ancienne version préfixait « +223 » sans condition. Un commerçant ivoirien
 * qui tapait ses dix chiffres se retrouvait enregistré sous un numéro malien
 * inexistant, et ne pouvait plus se connecter.
 */
class PhoneService
{
    /**
     * Forme canonique du numéro, dans le pays donné.
     *
     * Le pays est facultatif : à défaut, celui de la configuration, qui est
     * l'hypothèse valable pour les comptes créés avant que le pays ne soit
     * demandé.
     */
    public function normalize(string $phone, ?Country $country = null): string
    {
        return PhoneNumber::normalize($phone, $country ?? Country::default());
    }

    /**
     * Écritures possibles du numéro, pour retrouver un compte à la connexion.
     *
     * @return list<string>
     */
    public function candidates(string $phone, ?Country $country = null): array
    {
        return PhoneNumber::candidates($phone, $country ?? Country::default());
    }
}
