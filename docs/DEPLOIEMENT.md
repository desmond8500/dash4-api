# Déploiement de l'API sur alwaysdata

Cible : `https://api.dash4.yonkou.info`, hébergement mutualisé alwaysdata.
Le déploiement se fait automatiquement par GitHub Actions à chaque push sur `main`, une fois les étapes ci-dessous faites **une seule fois**.

---

## 1. Préparer le compte alwaysdata

1. **PHP** : *Environnement > PHP* → version **8.5** (ou 8.4).
2. **Base de données** : *Bases de données > MySQL* → créer une base (ex. `<compte>_dash4`) et un utilisateur. alwaysdata fournit MariaDB, compatible.
3. **SSH** : *Accès distant > SSH* → activer l'accès SSH de l'utilisateur.

## 2. Récupérer le code sur le serveur

Se connecter en SSH (`ssh <compte>@ssh-<compte>.alwaysdata.net`), puis :

```bash
cd ~
git clone https://github.com/desmond8500/dash4-api.git
cd dash4-api
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Modifier `.env` (`nano .env`) :

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.dash4.yonkou.info
LOG_LEVEL=warning

DB_CONNECTION=mariadb
DB_HOST=mysql-<compte>.alwaysdata.net
DB_DATABASE=<compte>_dash4
DB_USERNAME=<compte>
DB_PASSWORD=...

FRONTEND_URLS=https://dash4.yonkou.info,https://mobile.dash4.yonkou.info

ADMIN_EMAIL=votre@email
ADMIN_PASSWORD=un-mot-de-passe-solide
```

Puis :

```bash
php artisan migrate --force --seed   # crée les tables, les 7 profils et l'administrateur
php artisan storage:link
php artisan optimize
```

Une fois connecté une première fois, **vider `ADMIN_PASSWORD`** dans `.env`.

> Si le dépôt passe en privé, ajouter une clé de déploiement (*Deploy key*) sur GitHub pour que le serveur puisse faire `git fetch`.

## 3. Déclarer le site

*Web > Sites > Ajouter un site* :
- **Adresses** : `api.dash4.yonkou.info`
- **Type** : PHP
- **Répertoire racine** : `/dash4-api/public`
- **HTTPS** : certificat Let's Encrypt automatique

Côté DNS de `yonkou.info` : faire pointer `api.dash4` vers alwaysdata (enregistrements indiqués dans *Domaines*, ou domaine géré directement par alwaysdata).

Vérifier : `https://api.dash4.yonkou.info/api/v1/health` doit répondre `{"status":"ok",…}`.

## 4. Tâches de fond

- **Tâche planifiée** (*Avancé > Tâches planifiées*), toutes les minutes :
  `cd $HOME/dash4-api && php artisan schedule:run`
- **File d'attente** (PDF, e-mails) — au choix :
  - *Avancé > Services* : commande `php $HOME/dash4-api/artisan queue:work --sleep=3 --tries=3 --max-time=3600` (relancée automatiquement par alwaysdata) ;
  - ou une tâche planifiée toutes les minutes : `cd $HOME/dash4-api && php artisan queue:work --stop-when-empty`.

## 5. Déploiement automatique (GitHub Actions)

1. Sur votre poste : `ssh-keygen -t ed25519 -f dash4_deploy -N ""`.
2. Ajouter le contenu de `dash4_deploy.pub` à `~/.ssh/authorized_keys` sur alwaysdata.
3. Sur GitHub, *Settings > Secrets and variables > Actions* du dépôt `dash4-api`, créer :

| Secret | Valeur |
|---|---|
| `ALWAYSDATA_SSH_HOST` | `ssh-<compte>.alwaysdata.net` |
| `ALWAYSDATA_SSH_USER` | `<compte>` |
| `ALWAYSDATA_SSH_KEY` | contenu de `dash4_deploy` (clé privée) |
| `ALWAYSDATA_APP_PATH` | `/home/<compte>/dash4-api` |

À chaque push sur `main`, si les tests passent : mise à jour du code, `composer install`, migrations, mise à jour des profils et des permissions, cache.

Tant que ces secrets n'existent pas, l'étape de déploiement est simplement ignorée.
