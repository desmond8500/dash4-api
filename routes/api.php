<?php

use App\Http\Controllers\Api\V1\AssigneeController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ClientLogoController;
use App\Http\Controllers\Api\V1\ClientRequestController;
use App\Http\Controllers\Api\V1\ClientRequestConvertController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\LinkController;
use App\Http\Controllers\Api\V1\NoteController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectLogoController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Support\Attachable;
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
        Route::post('clients/{client}/logo', [ClientLogoController::class, 'store']);
        Route::delete('clients/{client}/logo', [ClientLogoController::class, 'destroy']);
        Route::apiResource('contacts', ContactController::class);

        Route::get('client-requests/summary', [ClientRequestController::class, 'summary'])->name('client-requests.summary');
        Route::apiResource('client-requests', ClientRequestController::class)
            ->parameters(['client-requests' => 'clientRequest']);

        Route::post('client-requests/{clientRequest}/convert', ClientRequestConvertController::class)->name('client-requests.convert');

        Route::apiResource('projects', ProjectController::class);
        Route::post('projects/{project}/logo', [ProjectLogoController::class, 'store']);
        Route::delete('projects/{project}/logo', [ProjectLogoController::class, 'destroy']);

        // Notes, liens et documents de toute fiche : clients, projets (voir App\Support\Attachable)
        Route::where(['type' => implode('|', array_keys(Attachable::TYPES)), 'id' => '[0-9]+'])->group(function () {
            Route::get('{type}/{id}/notes', [NoteController::class, 'index']);
            Route::post('{type}/{id}/notes', [NoteController::class, 'store']);
            Route::get('{type}/{id}/links', [LinkController::class, 'index']);
            Route::post('{type}/{id}/links', [LinkController::class, 'store']);
            Route::get('{type}/{id}/documents', [DocumentController::class, 'index']);
            Route::post('{type}/{id}/documents', [DocumentController::class, 'store']);
        });
        Route::patch('notes/{note}', [NoteController::class, 'update']);
        Route::delete('notes/{note}', [NoteController::class, 'destroy']);
        Route::patch('links/{link}', [LinkController::class, 'update']);
        Route::delete('links/{link}', [LinkController::class, 'destroy']);
        Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy']);

        Route::get('assignees', AssigneeController::class)->name('assignees');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
    });
});
