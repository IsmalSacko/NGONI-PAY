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
     * Droits que le propriétaire (ou un admin) accorde ou retire à chaque
     * gérant et caissier, en plus de son rôle : le rôle fixe la base, ces
     * interrupteurs le reste. Un admin a toujours tout.
     *
     * @var array<string, array{libelle: string, explication: string, permissions: list<string>}>
     */
    public const DROITS = [
        'chiffre_affaires' => [
            'libelle' => 'Voir le chiffre d’affaires',
            'explication' => 'Pilotage, rapports, statistiques, clôture de la journée et les ventes de toute la boutique.',
            'permissions' => ['dashboard.view', 'rapports.view', 'ventes.view_all'],
        ],
        'articles' => [
            'libelle' => 'Modifier les articles et les prix',
            'explication' => 'Créer, modifier ou supprimer des articles et des catégories, corriger le stock.',
            'permissions' => ['produits.create', 'produits.update', 'produits.delete', 'categories.create', 'categories.update', 'categories.delete', 'stocks.update'],
        ],
        'annuler_ventes' => [
            'libelle' => 'Annuler des ventes',
            'explication' => 'Annuler un ticket ; le stock est remis.',
            'permissions' => ['ventes.delete'],
        ],
        'achats' => [
            'libelle' => 'Achats et fournisseurs',
            'explication' => 'Réceptions de marchandise, fournisseurs et ce qu’on leur doit.',
            'permissions' => ['achats.view', 'achats.create'],
        ],
        'backoffice' => [
            'libelle' => 'Back-office web',
            'explication' => 'Gérer la boutique depuis un ordinateur.',
            'permissions' => ['backoffice.access'],
        ],
    ];

    /** Ce que chaque rôle reçoit si l'on ne précise rien (à la création, au changement de rôle). */
    public const DROITS_PAR_DEFAUT = [
        'gerant' => ['chiffre_affaires', 'articles', 'annuler_ventes', 'achats', 'backoffice'],
        'caissier' => [],
    ];

    /**
     * Permissions d'une liste de droits.
     *
     * @param  list<string>  $droits
     * @return list<string>
     */
    public static function permissionsDes(array $droits): array
    {
        return array_values(array_unique(array_merge([], ...array_map(fn (string $d) => self::DROITS[$d]['permissions'] ?? [], $droits))));
    }

    /** @return list<string> Toutes les permissions réglables par droit. */
    public static function permissionsReglables(): array
    {
        return self::permissionsDes(array_keys(self::DROITS));
    }

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
            // Réceptions de marchandise, fournisseurs et ce qu'on leur doit.
            'achats' => ['view', 'create'],
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
        ], self::permissionsReglables()));
        // Le reste (chiffre d'affaires, articles, annulations, achats,
        // back-office) n'est plus dans le rôle : accordé membre par membre
        // (DROITS), un gérant de plus dans le rôle aurait tout d'office.

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
