# Dash4 — API

API de l'ERP **Dash4**, refonte de Dash3. Laravel 13, PHP 8.4+, MariaDB / MySQL.

Le cahier des charges, le modèle de données, les profils et le périmètre sont dans le projet Dash4 :
`TODO.md`, `MODELE_DONNEES.md`, `PROFILS_PERMISSIONS.md`, `PERIMETRE_MVP.md`.

| Dépôt | Rôle |
|---|---|
| **dash4-api** | cette API |
| dash4-web | front web (Vue 3 + PrimeVue), sur Netlify |
| dash4-mobile | app mobile (Ionic 9 + Capacitor), gestionnaires et techniciens |
| dash4-shared | types TypeScript et client API communs |

## Ce qui est en place (socle)

- Connexion par jeton Bearer (Laravel Sanctum), limitée à 5 essais par minute
- **7 profils** et 103 permissions (`app/Support/Permissions.php`), créés par `RolesAndPermissionsSeeder`
- Règle **technicien** : jamais de prix, de coûts, de banque… même si un rôle les lui donne (`User::hasPermissionTo`)
- Gestion des utilisateurs : seul un administrateur gère les administrateurs, personne ne modifie ses propres rôles, il reste toujours un administrateur actif
- Comptes désactivés : jetons supprimés, accès refusé immédiatement
- Journal d'audit (spatie/laravel-activitylog) : connexions, modifications des utilisateurs, des rôles, des clients et des contacts
- **Clients et contacts** : téléphones et e-mails multiples, anti-doublon `client_uuid`, synchronisation incrémentale
- Messages de validation en français (`lang/fr`)
- CI GitHub Actions (tests PHP 8.4 et 8.5) et déploiement automatique sur alwaysdata

## Routes

| Méthode | Route | Accès |
|---|---|---|
| GET | `/api/v1/health` | public |
| POST | `/api/v1/auth/login` | public — `email`, `password`, `device_name` |
| GET | `/api/v1/auth/me` | connecté — utilisateur, rôles, permissions effectives |
| POST | `/api/v1/auth/logout` | connecté |
| GET | `/api/v1/roles` | `users.view` |
| GET | `/api/v1/users` | `users.view` — filtres `search`, `account_type`, `is_active`, `role` |
| POST | `/api/v1/users` | `users.manage` |
| GET | `/api/v1/users/{id}` | `users.view` ou soi-même |
| PATCH | `/api/v1/users/{id}` | `users.manage` — désactiver : `is_active: false` |
| GET | `/api/v1/clients` | `clients.view` — filtres `search`, `type`, `status`, `favorite`, tri `sort=-updated_at` |
| POST | `/api/v1/clients` | `clients.create` — `phones[]`, `emails[]`, `client_uuid` (anti-doublon mobile) |
| GET | `/api/v1/clients/{id}` | `clients.view` — avec contacts, téléphones, e-mails |
| PATCH | `/api/v1/clients/{id}` | `clients.update` — `phones` / `emails` envoyés remplacent les listes |
| DELETE | `/api/v1/clients/{id}` | `clients.delete` — corbeille |
| GET POST GET PATCH DELETE | `/api/v1/contacts…` | mêmes droits que les clients — filtres `search`, `client_id` |

**Synchronisation mobile** : toute liste accepte `updated_since` (date ISO) et renvoie alors seulement les fiches modifiées depuis, **suppressions comprises** (`deleted_at` rempli), jusqu'à 500 par page. `meta.server_time` est la valeur à renvoyer à la synchro suivante.

Toutes les réponses sont en JSON. Les erreurs de validation renvoient `422` avec `errors`.

## Installation locale

```bash
docker compose up -d          # MariaDB 11.8 + Mailpit
composer install
cp .env.example .env          # DB_PASSWORD=dash4, ADMIN_EMAIL / ADMIN_PASSWORD à remplir
php artisan key:generate
php artisan migrate --seed
php artisan serve             # http://localhost:8000/api/v1/health
```

Tests : `php artisan test` (SQLite en mémoire, aucune base à créer).

Une fois `composer install` lancé une première fois, **committer `composer.lock`** pour figer les versions.

## Déploiement

Voir [docs/DEPLOIEMENT.md](docs/DEPLOIEMENT.md) (alwaysdata, `api.dash4.yonkou.info`).

## Conventions

- Code, tables et colonnes en **anglais** ; messages et interface en **français**
- Routes versionnées `/api/v1`, réponses via API Resources, validation via Form Requests
- Toute vérification de droits côté serveur (Policies + permissions)
- Un test pour chaque règle métier
