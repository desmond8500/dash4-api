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

| GET POST GET PATCH DELETE | `/api/v1/quotes…` | `quotes.view` / `.view.own` (ses devis : créés, ou de ses projets), `create`, `update` / `.update.own`, `delete` — filtres `search`, `status`, `client_id`, `project_id`, `year` |
| POST | `/api/v1/quotes/{id}/status` | brouillon → envoyé → accepté / refusé (`quotes.validate`), annulé, rouvert |
| POST | `/api/v1/quotes/{id}/duplicate` | nouveau brouillon, nouveau numéro |
| GET | `/api/v1/quotes/{id}/pdf` | `prices.view` — PDF avec logo, banque, signature et cachet (`inline=1` pour l'afficher) |
| POST | `/api/v1/quotes/{id}/signed-copy` | `quotes.validate` — copie signée par le client, devis accepté |
| POST PATCH DELETE | `/api/v1/quotes/{id}/sections`, `/quote-sections/{id}`, `/quote-sections/{id}/lines`, `/quote-lines/{id}` ; `…/order` | sections et lignes ; coût et coefficient : `costs.view` |

| GET POST PATCH DELETE | `/api/v1/quote-systems`, `/api/v1/quote-templates…` | modèles de devis rangés par système ; gestion : `quotes.update` + `costs.view` |
| POST | `/api/v1/quote-sections/{id}/save-as-template`, `/api/v1/quotes/{id}/insert-template` | section → modèle ; modèle → devis (nouvelle section ou section existante) |
| GET | `/api/v1/quotes/{id}/proposal-pdf` | proposition technique (texte `proposal` par section, `proposal_options` du devis) |
| POST PATCH DELETE | `/api/v1/quotes/{id}/deposits`, `/quote-deposits/{id}` | acomptes en montant ou `percent` — `prices.view` |
| POST DELETE | `/api/v1/quotes/{id}/expenses`, `/quote-expenses/{id}` (POST pour modifier, multipart) | dépenses réelles avec justificatif — `costs.view` |
| POST PATCH DELETE GET | `/api/v1/quotes/{id}/delivery-notes`, `/delivery-notes/{id}`, `/delivery-notes/{id}/pdf` | bordereaux de livraison ou de travaux `BL-2026-0001`, sans prix |
| GET POST | `/api/v1/quotes/{id}/email-draft`, `/api/v1/quotes/{id}/send` | envoi par e-mail avec le PDF (et la proposition), historique ; réglages `MAIL_*` du serveur |

| GET POST PATCH DELETE | `/api/v1/email-signatures…` | signatures d'e-mail : les siennes, et celles de l'entreprise (`shared`, `company.update`) ; variables `{nom}` `{prenom}` `{fonction}` `{telephone}` `{email}` `{entreprise}` `{site}` `{adresse}` ; `signature_id` à l'envoi |

| GET POST GET PATCH DELETE | `/api/v1/invoices…` | `invoices.view` / `create` / `update` / `delete` (brouillon seulement) — filtres `status`, `unpaid=1`, `overdue=1`, `client_id`, `quote_id`, `project_id`, `year`, `search` |
| POST | `/api/v1/invoices` | depuis un devis accepté : `quote_id`, `scope` (`all`, `equipment`, `equipment_accessories`, `labor`, `remainder`), `percent`, `title` ; depuis un acompte prévu : `quote_deposit_id` ; libre : `client_id` |
| GET | `/api/v1/invoices/summary` | à encaisser, en retard, encaissé ce mois-ci, brouillons |
| POST PATCH DELETE | `/api/v1/invoices/{id}/lines`, `/invoice-lines/{id}` | lignes d'un brouillon (prix négatif : remise, déduction) |
| POST | `/api/v1/invoices/{id}/issue`, `/cancel` | `invoices.issue` — numéro `FAC-2026-0001` à l'émission, échéance à 30 jours par défaut |
| POST DELETE | `/api/v1/invoices/{id}/payments`, `/invoice-payments/{id}` | paiements (virement, chèque, espèces, Wave, Orange Money, Free Money, carte, autre) |
| GET POST | `/api/v1/invoices/{id}/pdf`, `/email-draft`, `/send` | PDF (« Brouillon » en filigrane) et envoi par e-mail |

| GET POST PATCH DELETE | `/api/v1/brands`, `/providers`, `/articles` | `stock.view` (technicien : sans prix ni coût) / `stock.create` / `update` / `delete` — articles : `search`, `barcode`, `low=1`, `category`, `brand_id`, `provider_id` ; `initial_quantity` à la création |
| GET | `/api/v1/articles/summary`, `/stock-movements` | stock bas, ruptures, valeur du stock (`costs.view`) ; journal des mouvements |
| POST | `/api/v1/articles/{id}/movements` | `stock.move` — `type` `in` / `out` / `inventory`, `quantity`, `reason`, `project_id`, `client_uuid` |
| POST DELETE PUT | `/api/v1/articles/{id}/image`, `/dependencies` | image, articles liés |
| POST | `/api/v1/delivery-notes/{id}/release`, `/restock` | bordereau livré : sortie du stock (tout ou rien) ; annulation |
| GET POST PATCH DELETE | `/api/v1/purchases…`, `/purchase-lines/{id}` | `purchases.*` — bon de commande `BC-2026-0001`, `low_stock=1` |
| POST | `/api/v1/purchases/{id}/order`, `/cancel`, `/receive` | commander, annuler, réceptionner (`purchases.receive`, partiel possible, coût mis à jour) |

| GET POST PATCH DELETE | `/api/v1/expenses…` | `accounting.*` (tout) ou `accounting.*.own` (ses dépenses et ses caisses) — filtres `status`, `mine`, `to_reimburse`, `expense_space_id`, `project_id`, `quote_id`, `from`, `to` ; `file` = justificatif |
| POST | `/api/v1/expenses/{id}/approve`, `/reject` (`reason`), `/reimburse`, `/receipt` | `accounting.update` — dépense du comptable validée d'office |
| GET POST PATCH DELETE | `/api/v1/expense-spaces…`, `/expense-spaces/{id}/fundings` | caisses : membres, versements, solde (versé − dépenses non avancées et non refusées) |
| GET POST PATCH DELETE | `/api/v1/expense-categories`, `/transactions` | catégories ; transactions saisies à la main (`direction` in / out) |
| GET | `/api/v1/accounting/summary`, `/accounting/ledger` | `accounting.view` — bilan (`from`, `to`, `project_id`) : paiements de factures, dépenses validées, achats reçus (TVA comprise), transactions |

**Devis** : prix d'une ligne = coût × coefficient (arrondi au franc) ; remise appliquée avant la taxe ; TVA ajoutée, BRS déduite ; taux figés à la création ; section masquée ni imprimée ni comptée ; un devis accepté, refusé ou annulé ne se modifie plus (dupliquer). Montants avec `prices.view`, coûts et marge avec `costs.view`.

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
