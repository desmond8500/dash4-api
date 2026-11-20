<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/** Mot de passe oublié : lien par e-mail, puis nouveau mot de passe (sans être connecté). */
class PasswordResetController extends Controller
{
    /**
     * POST /api/v1/auth/forgot-password — `email`. La réponse est la même que le compte existe ou non,
     * pour ne pas révéler les adresses connues ; rien n'est envoyé à un compte désactivé.
     */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']], [], ['email' => 'adresse e-mail']);
        $user = User::query()->where('email', $data['email'])->first();
        if ($user?->is_active) {
            Password::broker()->sendResetLink(['email' => $user->email]);
        }

        return response()->json(['message' => 'Si un compte actif utilise cette adresse, un lien pour choisir un nouveau mot de passe vient de lui être envoyé.']);
    }

    /** POST /api/v1/auth/reset-password — `token`, `email`, `password`, `password_confirmation`. */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ], ['password.confirmed' => 'Les deux mots de passe ne correspondent pas.'], ['password' => 'nouveau mot de passe']);

        $status = Password::broker()->reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password)])->save();
            // Toutes les sessions ouvertes sont fermées
            $user->tokens()->delete();
            activity('users')->performedOn($user)->causedBy($user)->log('Mot de passe réinitialisé');
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => [match ($status) {
                Password::INVALID_USER => 'Aucun compte n\'utilise cette adresse.',
                Password::RESET_THROTTLED => 'Trop de tentatives : réessayez dans quelques minutes.',
                default => 'Ce lien n\'est plus valable. Demandez-en un nouveau.',
            }]]);
        }

        return response()->json(['message' => 'Mot de passe changé. Vous pouvez vous connecter.']);
    }
}
