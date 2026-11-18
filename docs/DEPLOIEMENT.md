# Déploiement de l'API sur alwaysdata

Cible : `https://api.dash4.yonkou.info`, hébergement mutualisé alwaysdata.
Mise à jour **manuelle** en SSH (voir section 5) ; les étapes 1 à 4 ne se font qu'une seule fois.

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
  `cd /home/smi/www/projets/dash4-api && php artisan schedule:run`
- **File d'attente** (PDF, e-mails) — au choix :
  - *Avancé > Services* : commande `php /home/smi/www/projets/dash4-api/artisan queue:work --sleep=3 --tries=3 --max-time=3600` (relancée automatiquement par alwaysdata) ;
  - ou une tâche planifiée toutes les minutes : `cd /home/smi/www/projets/dash4-api && php artisan queue:work --stop-when-empty`.

## 5. Mettre à jour l'API (à chaque nouvelle version)

En SSH sur alwaysdata :

```bash
cd /home/smi/www/projets/dash4-api
git pull
composer install --no-dev --optimize-autoloader
php artisan optimize:clear      # oublie l'ancienne liste des routes et la config en cache
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan storage:link        # une seule fois suffit (logos des clients)
php artisan optimize
```

Vérifier : `git log -1 --oneline` montre le dernier commit de `main`, et `php artisan route:list --path=api/v1` liste les routes attendues.

Le déploiement automatique par SSH n'est pas utilisé (choix du 18/11/2026).
