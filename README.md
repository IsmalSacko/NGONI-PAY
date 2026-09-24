# e-caisse — API

SaaS de caisse tactile pour le commerce de détail en Afrique de l'Ouest
(zone FCFA en tête) : encaissement, pilotage et stocks pour une boutique,
avec un mode hors ligne pensé pour une connectivité intermittente.

## Démarrage

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # sqlite en local, zéro config
php artisan migrate --seed       # rejoue le jeu de données de démonstration
php artisan serve --port=8001
```

Comptes de démonstration (voir `database/seeders/DemoSeeder.php`), boutique
« Épicerie Koné » :

| Rôle      | Téléphone         | Mot de passe |
|-----------|-------------------|--------------|
| admin     | `+223 76 00 00 00`| `password`   |
| gérant    | `+223 77 00 00 00`| `password`   |
| caissier  | `+223 78 00 00 00`| `password`   |

Documentation API générée (Scramble) : `/docs/api` une fois le serveur lancé.

## Architecture

- **Multi-tenant** : `Boutique` est la racine. Toute table métier porte
  `boutique_id` et passe par le trait
  `App\Models\Concerns\BelongsToBoutique`, qui applique le filtre tenant
  (`BoutiqueScope`) et le renseigne à la création à partir du
  `TenantContext` posé par le middleware `tenant`. `boutique_id` n'est
  jamais accepté depuis une requête cliente : toujours déduit du compte
  authentifié.
- **Rôles** (`Spatie\Permission`, isolés par équipe = boutique) : `admin`
  (créateur du compte, seul à gérer l'équipe et les réglages), `gerant`
  (catalogue, stocks, rapports), `caissier` (caisse tactile seule). Matrice
  dans `App\Support\Authorization\Permissions`, rejouée par
  `php artisan ecaisse:sync-role-permissions`.
- **Pays/téléphone/devise** : `App\Enums\Country` (Afrique de l'Ouest,
  UEMOA en tête) + `App\Support\Phone\PhoneNumber` pour la tolérance de
  saisie (numéro local, international, avec ou sans « + »). Chaque pays
  propose une devise par défaut, modifiable par la boutique.
- **Domaine** : `CategorieProduit` → `Produit` (prix, TVA, stock, seuil
  d'alerte) ; `Vente` → `LigneVente` (prix et TVA dupliqués à la vente, le
  ticket ne doit pas bouger si le catalogue change ensuite). Chaque vente
  décrémente le stock et écrit une ligne dans `MouvementStock`, journal
  d'audit purement déclaratif — voir `App\Services\VenteService`.
- **Hors ligne** : `Vente.reference_locale` est l'UUID généré par la
  tablette au moment de l'encaissement. `VenteService::encaisser()` est
  idempotent sur cette référence : une vente hors ligne rejouée au retour du
  réseau ne se double pas. Les prix et taux de TVA ne sont jamais pris
  depuis le client, toujours relus depuis le catalogue serveur au moment de
  l'encaissement.
- **API v1** (`routes/api.php`), pensée pour un client tactile
  (tablette/desktop, voir le choix technique ci-dessous) et un futur
  back-office Livewire partageant les mêmes modèles et services.

## Tests

```bash
php artisan test
```

## Client (à construire)

Pas encore de client dans ce dépôt. Recommandation retenue : Flutter pour
la caisse tactile (tablette Android + desktop, un seul code, SQLite local
via `drift` pour la file d'attente hors ligne, accès natif au scanner
code-barres et à l'imprimante thermique ESC/POS) plutôt qu'une PWA — la
robustesse du mode hors ligne et l'intégration matérielle priment sur le
déploiement sans store.

## Ce qui reste à construire

- Client Flutter (caisse tactile, pilotage, stocks) — les maquettes de
  référence sont dans l'artefact de design partagé au démarrage du projet.
- Back-office Livewire (catalogue, rapports, équipe), sur le modèle de
  `sabati-api`.
- Génération du ticket 80 mm en PDF/ESC-POS (le modèle `Vente` porte déjà
  tout le nécessaire : numéro, lignes, TVA, moyen de paiement).
- Gestion des sessions de caisse (ouverture/fermeture, fond de caisse,
  écart) — non modélisée pour l'instant.
- Abonnement/facturation de la plateforme, si le modèle SaaS en a besoin.
