# e-caisse — API

SaaS de caisse tactile pour le commerce de détail en Afrique de l'Ouest
(zone FCFA en tête) : encaissement, pilotage et stocks pour une boutique,
avec un mode hors ligne pensé pour une connectivité intermittente.

## Démarrage

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # sqlite en local, zéro config
php artisan migrate --seed       # rejoue le jeu de données de démonstration
npm run build                    # ou `npm run dev` pendant le développement
php artisan serve --port=8001
```

Comptes de démonstration (voir `database/seeders/DemoSeeder.php`), boutique
« Épicerie Koné » — back-office web sur `/connexion` :

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
- **Séances de caisse** : un caissier ouvre sa caisse avec un fond initial
  (`App\Services\SessionCaisseService::ouvrir`), chaque vente encaissée
  pendant la séance s'y rattache automatiquement (`Vente.session_caisse_id`,
  posé par `VenteService`, jamais fourni par le client). À la fermeture,
  l'écart est calculé une seule fois et figé (`fond_final` déclaré − (fond
  initial + espèces encaissées pendant la séance)) — un ticket annulé après
  coup ne doit pas faire bouger l'écart d'une séance déjà close. Une
  personne ne peut fermer que sa propre séance, sauf `gerant`/`admin`.
- **API v1** (`routes/api.php`) pour le futur client tactile (tablette/
  desktop, voir le choix technique ci-dessous), **Livewire** (`routes/web.php`,
  `app/Livewire/`) pour le back-office web — les deux s'appuient sur les
  mêmes modèles et services pour ne jamais afficher deux chiffres différents.
- **Livewire et l'AJAX de mise à jour** : `/livewire/update` (l'endpoint que
  Livewire appelle pour chaque action après le rendu initial) ne passe QUE
  par le groupe de middleware `web` — jamais par les middlewares de la route
  qui a rendu la page (`tenant` compris, voir
  `Livewire\Mechanisms\HandleRequests\HandleRequests::boot()`). Chaque
  composant Livewire authentifié utilise donc le trait
  `App\Livewire\Concerns\EstScopeParBoutique`, qui rejoue `SetTenantContext`
  dans le hook `boot()` du composant (exécuté à CHAQUE requête, initiale et
  suivantes) — sans lui, `boutique_id` ne serait jamais renseigné à la
  création depuis une action Livewire. Chaque action mutante vérifie aussi
  la permission explicitement (`Auth::user()->can(...)`) : le middleware
  `permission:*` ne protège que le chargement initial de la page, pas les
  appels AJAX suivants.

## Tests

```bash
php artisan test
```

## Client tactile

Dans `../e-caisse-front` (Flutter — tablette Android + desktop, un seul
code, SQLite local pour la file d'attente hors ligne) plutôt qu'une PWA —
la robustesse du mode hors ligne et l'intégration matérielle (scanner,
imprimante ESC/POS) priment sur le déploiement sans store. Écrans en place :
connexion, caisse tactile (avec ouverture/fermeture de séance, scanner
code-barres en mode clavier, bascule FR/BM), pilotage, stocks, historique
des ventes, ticket (aperçu + impression Bluetooth ESC/POS). Détails dans
son propre README.

## Écrans Livewire (`app/Livewire/`)

Auth\Login, Dashboard, Produits\Index (catalogue), Categories\Index,
Clients\Index (fidélité), Ventes\Index (historique + détail ticket),
Stocks\Index (ajustement d'inventaire), Utilisateurs\Index (équipe, création
de comptes avec rôle). Chacun a un test dans `tests/Feature/BackofficeTest.php`.

## Ce qui reste à construire

- Écran de suivi des séances de caisse côté back-office Livewire (l'API
  existe — `/api/sessions-caisse` — mais aucun écran `gerant`/`admin` ne
  liste encore les séances passées pour repérer les écarts).
- Premier appairage réel d'une imprimante ESC/POS (le format des octets est
  généré et testable côté Flutter, jamais vérifié contre du matériel — voir
  le README de `../e-caisse-front`).
- Import/export Excel des produits et des rapports (mentionnés dans la
  maquette, pas encore branchés).
- Abonnement/facturation de la plateforme, si le modèle SaaS en a besoin.
