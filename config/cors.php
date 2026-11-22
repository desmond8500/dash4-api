<?php

return [

    'paths' => ['api/*', 'up'],

    'allowed_methods' => ['*'],

    // Domaines des fronts (Netlify, local), définis par FRONTEND_URLS dans le .env
    // Fronts web (FRONTEND_URLS) + application mobile installée (Capacitor : Android https://localhost, iOS capacitor://localhost)
    'allowed_origins' => array_values(array_unique(array_filter([
        ...array_map('trim', explode(',', (string) env('FRONTEND_URLS', 'http://localhost:5173,http://localhost:8100'))),
        'https://localhost',
        'capacitor://localhost',
    ]))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Authentification par jeton (Authorization: Bearer) : pas de cookies cross-domain
    'supports_credentials' => false,

];
