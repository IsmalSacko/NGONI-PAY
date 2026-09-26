<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Console\Commands\SyncRolePermissions;

/**
 * Matrice des rôles : qui, chez e-caisse, a le droit de faire quoi.
 *
 * Trois rôles fixes, provisionnés à chaque boutique
 * ({@see SyncRolePermissions}) :
 * - `admin` — le compte qui a créé la boutique à l'inscription. Seul rôle à
 *   pouvoir gérer les comptes de l'équipe et les réglages de la boutique.
 * - `gerant` — fait tourner la boutique au quotidien : catalogue, stocks,
 *   rapports. Ne touche pas aux comptes ni aux réglages sensibles.
 * - `caissier` — la caisse tactile, et rien d'autre : encaisser, consulter
 *   le stock affiché sur les tuiles, ajouter un client à la volée, revoir ses
 *   propres ventes. Ne voit ni le pilotage, ni l'équipe, ni les ventes des
 *   autres, et n'entre pas dans le back-office web.
 *
 * Une seule source pour la matrice, rejouée par une commande plutôt que
 * réglée à la main dans chaque environnement : une permission ajoutée demain
 * pour une fonctionnalité nouvelle doit atteindre les boutiques déjà
 * inscrites sans reprise manuelle.
 */
class Permissions
{
    /**
     * @return array<string, list<string>>
     */
    public static function catalogue(): array
    {
        return [
            'boutique' => ['view', 'update'],
            'categories' => ['view', 'create', 'update', 'delete'],
            'produits' => ['view', 'create', 'update', 'delete'],
            'stocks' => ['view', 'update'],
            // view_all : l'historique de toute la boutique. Sans elle, chacun
            // ne voit que ses propres ventes.
            'ventes' => ['view', 'view_all', 'create', 'delete'],
            'sessions_caisse' => ['view', 'create', 'update'],
            'clients' => ['view', 'create', 'update', 'delete'],
            'utilisateurs' => ['view', 'create', 'update', 'delete'],
            'rapports' => ['view'],
            'dashboard' => ['view'],
            // Demander un abonnement engage le propriétaire : réservé à l'admin.
            'abonnement' => ['manage'],
            // Le back-office web : gérer la boutique depuis un ordinateur.
            // Les caissiers encaissent depuis l'application, sans y accéder.
            'backoffice' => ['access'],
        ];
    }

    /**
     * Toutes les permissions du catalogue, à plat (« produits.view »…).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return self::flatten(self::catalogue());
    }

    /**
     * Permissions accordées à chaque rôle, à plat (« produits.view »…).
     *
     * @return array<string, list<string>>
     */
    public static function roleMatrix(): array
    {
        $toutes = self::all();

        $gerant = array_values(array_diff($toutes, [
            // Les comptes et les réglages de la boutique restent au
            // titulaire : ils engagent qui a accès à quoi, et comment la
            // boutique est identifiée (nom, devise, pays).
            'boutique.update',
            'utilisateurs.create', 'utilisateurs.update', 'utilisateurs.delete',
            'abonnement.manage',
        ]));

        $caissier = [
            'produits.view', 'categories.view', 'stocks.view',
            'ventes.view', 'ventes.create',
            'sessions_caisse.view', 'sessions_caisse.create', 'sessions_caisse.update',
            'clients.view', 'clients.create',
        ];

        return [
            'admin' => $toutes,
            'gerant' => $gerant,
            'caissier' => $caissier,
        ];
    }

    /**
     * @param  array<string, list<string>>  $catalogue
     * @return list<string>
     */
    private static function flatten(array $catalogue): array
    {
        $permissions = [];

        foreach ($catalogue as $ressource => $actions) {
            foreach ($actions as $action) {
                $permissions[] = "{$ressource}.{$action}";
            }
        }

        return $permissions;
    }
}
