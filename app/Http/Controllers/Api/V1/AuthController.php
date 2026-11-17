<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** POST /api/v1/auth/login — renvoie un jeton Bearer. */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', (string) $request->input('email'))->first();

        if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Adresse e-mail ou mot de passe incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Ce compte est désactivé.'], 403);
        }

        $expiresAt = now()->addMinutes(config('dash4.token_lifetime_minutes'));
        $token = $user->createToken($request->input('device_name') ?: 'Dash4', ['*'], $expiresAt);

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        activity('auth')->performedOn($user)->causedBy($user)->event('login')->log('Connexion');

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => (new UserResource($user))->withPermissions(),
        ]);
    }

    /** GET /api/v1/auth/me — l'utilisateur connecté, avec ses permissions effectives. */
    public function me(Request $request): UserResource
    {
        return (new UserResource($request->user()))->withPermissions();
    }

    /** POST /api/v1/auth/logout — supprime le jeton utilisé. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }
}
