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

];
