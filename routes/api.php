<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

/*
| API Dash4 — version 1. Toutes les routes sont préfixées par /api/v1.
| Authentification : jeton Bearer (Laravel Sanctum).
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('health', fn () => response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'time' => now()->toIso8601String(),
    ]))->name('health');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::apiResource('users', UserController::class)->except('destroy');

        Route::apiResource('clients', ClientController::class);
        Route::apiResource('contacts', ContactController::class);
    });
});
