<?php

use Illuminate\Support\Facades\Route;

// Dash4 est une API : la racine indique simplement où trouver l'API.
Route::get('/', fn () => response()->json([
    'app' => config('app.name'),
    'api' => url('/api/v1'),
]));
