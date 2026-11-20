<?php

return [

    /*
    | Premier administrateur, créé par `php artisan db:seed` (AdminUserSeeder).
    | Laisser vide une fois le compte créé.
    */
    'admin' => [
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
        'firstname' => env('ADMIN_FIRSTNAME', 'Admin'),
        'lastname' => env('ADMIN_LASTNAME', 'Dash4'),
    ],

    /*
    | Durée de vie des jetons de connexion, en minutes (30 jours par défaut).
    */
    'token_lifetime_minutes' => (int) env('TOKEN_LIFETIME_MINUTES', 43200),

    /*
    | Adresse du front web : lien des e-mails « mot de passe oublié » (valable aussi pour les techniciens).
    */
    /*
    | Sauvegardes (php artisan backup:run) : nombre de jours gardés sur le serveur.
    */
    'backups' => [
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),
    ],

    /*
    | Comptes du journal des écritures (export « FEC »), plan SYSCOHADA. Valeurs par défaut à faire
    | valider par votre comptable ; à changer ici si besoin.
    */
    'accounting' => [
        'clients' => '411000',
        'suppliers' => '401000',
        'sales' => '706000',
        'purchases' => '601000',
        'expenses' => '638000',
        'vat_collected' => '443100',
        'vat_deductible' => '445200',
        'brs' => '449100',
        'bank' => '521000',
        'cash' => '571000',
        'mobile_money' => '521100',
        'other_income' => '758000',
        'other_charges' => '658000',
    ],

    'web_url' => rtrim((string) env('WEB_URL', 'https://dash4web.netlify.app'), '/'),

    /*
    | Demandes clients : une demande encore « Nouvelle » après ce nombre de jours
    | est en retard et une relance est envoyée (TODO §3).
    */
    'client_requests' => [
        'overdue_days' => (int) env('CLIENT_REQUESTS_OVERDUE_DAYS', 2),
    ],

    /*
    | Boîte mail des demandes, relevée en IMAP toutes les 5 minutes (php artisan inbox:fetch).
    | alwaysdata : INBOX_HOST=imap.alwaysdata.com, INBOX_PORT=993, INBOX_ENCRYPTION=ssl,
    | INBOX_USERNAME = adresse complète. Vide : pas de relevé.
    */
    'inbox' => [
        'host' => env('INBOX_HOST'),
        'port' => (int) env('INBOX_PORT', 993),
        'encryption' => env('INBOX_ENCRYPTION', 'ssl'),
        'username' => env('INBOX_USERNAME'),
        'password' => env('INBOX_PASSWORD'),
        'folder' => env('INBOX_FOLDER', 'INBOX'),
        'address' => env('INBOX_ADDRESS'),
    ],

];
