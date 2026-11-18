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
- CI GitHub Actions (tests PHP 8.4 et 8.5) ; mise à jour du serveur alwaysdata à la main, voir `docs/DEPLOIEMENT.md`

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
| POST DELETE | `/api/v1/clients/{id}/logo` | `clients.update` — envoi multipart, champ `logo` (JPEG, PNG, WebP, 2 Mo) ; réponse : `logo_url` |
| GET POST GET PATCH DELETE | `/api/v1/contacts…` | mêmes droits que les clients — filtres `search`, `client_id` |

| GET | `/api/v1/client-requests` | `client_requests.view` (tout) ou `.view.own` (les siennes) — filtres `open=1`, `status=new,in_progress`, `assigned=me\|none`, `client_id`, `priority`, `source`, `overdue=1`, `search` |
| GET | `/api/v1/client-requests/summary` | compteurs : à traiter, nouvelles, en retard, confiées à moi, rappels arrivés |
| POST | `/api/v1/client-requests` | `client_requests.create` — seul `body` est obligatoire ; un technicien ne fixe ni statut, ni personne en charge, ni rappel |
| PATCH | `/api/v1/client-requests/{id}` | `client_requests.update` — statut, priorité, `assigned_to`, `remind_at`… |
| DELETE | `/api/v1/client-requests/{id}` | `client_requests.delete` — corbeille |
| GET | `/api/v1/assignees` | personnes actives à qui confier une demande (nom seulement) |
| GET POST | `/api/v1/notifications`, `/notifications/{id}/read`, `/notifications/read-all` | notifications de l'utilisateur connecté (relances des demandes) |

**Relance des demandes clients** : `php artisan client-requests:remind`, lancé toutes les heures par le planificateur (`schedule:run`), notifie une fois les demandes encore « Nouvelle » après 2 jours (`CLIENT_REQUESTS_OVERDUE_DAYS`) et les dates de rappel arrivées.

| GET POST GET PATCH DELETE | `/api/v1/projects…` | `projects.view` (tous) ou `.view.own` (ses projets) ; `update` ou `update.own` — filtres `search`, `status`, `active=1`, `client_id`, `manager=me`, `favorite=1`, `include=notes,links` |
| POST DELETE | `/api/v1/projects/{id}/logo` | comme le logo d'un client |
| POST | `/api/v1/client-requests/{id}/convert` | `client_requests.convert` + `projects.create` — `type: project` ; la demande passe à « Traitée » |
| GET POST | `/api/v1/{clients\|projects}/{id}/notes`, `/links`, `/documents` | droits de la fiche : la voir pour lire, la modifier pour ajouter |
| PATCH DELETE | `/api/v1/notes/{id}`, `/api/v1/links/{id}` ; DELETE `/api/v1/documents/{id}` | l'auteur, ou qui peut supprimer la fiche |
| GET | `/api/v1/documents/{id}/download` | fichier privé, téléchargé avec le jeton (20 Mo ; pas d'exécutable, HTML ni SVG) |

| GET PATCH | `/api/v1/company` | `company.view` / `company.update` ; `signature_on` : `signature.manage` |
| POST DELETE | `/api/v1/company/logo` | `company.update` |
| GET POST DELETE | `/api/v1/company/{signature\|stamp}` | `signature.manage` — PNG privé, administrateurs prévenus |
| GET POST PATCH DELETE | `/api/v1/bank-accounts` | `bank.view` / `bank.manage` — numéros chiffrés, un seul compte par défaut, administrateurs prévenus |
| GET PATCH | `/api/v1/settings` | lecture : tout compte ; `settings.update` — devise (XOF), TVA 18 %, BRS 5 %, taxe par défaut, numérotation |
| GET | `/api/v1/settings/numbering-preview` | prochain numéro d'un type de document |

**Taxes (comme Dash3)** : TVA ajoutée au total hors taxes, BRS (retenue à la source, entreprises individuelles) déduite du total, ou sans taxe — `App\Support\TaxCalculator`. **Numérotation** : `DEV-{YYYY}-{####}`, un compteur par type et par année — `App\Support\DocumentNumber`.

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
