# dash4-api — consignes

- API Laravel 13 de l'ERP Dash4. Spécifications dans le projet Dash4 (`TODO.md`, `MODELE_DONNEES.md`, `PROFILS_PERMISSIONS.md`, `PERIMETRE_MVP.md`).
- Code, tables, colonnes en anglais ; messages d'erreur et libellés en français.
- Routes sous `/api/v1`, réponses via API Resources, validation via Form Requests, droits via Policies + permissions Spatie (`module.action`).
- Les permissions et les 7 profils sont définis uniquement dans `app/Support/Permissions.php` ; relancer `RolesAndPermissionsSeeder` après modification.
- Un compte technicien ne doit jamais recevoir de prix ni de coût : ne pas renvoyer ces champs sans `prices.view` / `costs.view`.
- Production sur hébergement mutualisé alwaysdata (MariaDB) : pas de fonctionnalité propre à MySQL 9, pas de processus permanent obligatoire.
- Push direct sur `main` ; chaque règle métier a son test Pest.
