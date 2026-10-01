<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * Ce qui peut attendre que l'utilisateur ait sa réponse : notifications push,
 * e-mails, alertes à l'exploitant. Servi par PHP-FPM, le travail part une fois
 * la réponse envoyée (l'écran ne patiente plus le temps d'un serveur de mails
 * ou de Firebase) ; en ligne de commande (tâches planifiées, tests), tout de
 * suite, comme avant.
 */
final class Apres
{
    public static function reponse(Closure $travail): void
    {
        if (app()->runningInConsole()) {
            $travail();

            return;
        }
        app()->terminating($travail);
    }
}
