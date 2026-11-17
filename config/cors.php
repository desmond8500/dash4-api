<?php

return [

    'paths' => ['api/*', 'up'],

    'allowed_methods' => ['*'],

    // Domaines des fronts (Netlify, local), définis par FRONTEND_URLS dans le .env
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'FRONTEND_URLS',
        'http://localhost:5173,http://localhost:8100',
    ))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Authentification par jeton (Authorization: Bearer) : pas de cookies cross-domain
    'supports_credentials' => false,

];
