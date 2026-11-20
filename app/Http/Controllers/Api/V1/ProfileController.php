<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClientLogoRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

/** Espace personnel : son profil, sa photo et son mot de passe (aucune permission requise). */
class ProfileController extends Controller
{
    /** PATCH /api/v1/me — prénom, nom, téléphone, fonction, `preferences` (thème, notifications) ; l'e-mail et les rôles se changent par un administrateur. */
    public function update(Request $request): UserResource
    {
        $user = $request->user();
        $data = $request->validate([
            'firstname' => ['sometimes', 'required', 'string', 'max:100'],
            'lastname' => ['sometimes', 'required', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preferences' => ['sometimes', 'array'],
            'preferences.theme' => ['sometimes', \Illuminate\Validation\Rule::in(\App\Support\Preferences::THEMES)],
            'preferences.notifications' => ['sometimes', 'array:'.implode(',', array_keys(\App\Support\Preferences::CATEGORIES))],
            'preferences.notifications.*' => ['boolean'],
        ], [], ['firstname' => 'prénom', 'lastname' => 'nom', 'phone' => 'téléphone', 'job_title' => 'fonction', 'preferences.theme' => 'thème']);
        if (isset($data['preferences'])) {
            $user->forceFill(['preferences' => \App\Support\Preferences::merge($user, $data['preferences'])]);
            unset($data['preferences']);
        }
        $user->fill($data)->save();

        return (new UserResource($user->refresh()))->withPermissions();
    }

    /** POST /api/v1/me/avatar — `logo` : photo (JPEG, PNG, WebP, 2 Mo). */
    public function avatar(ClientLogoRequest $request): UserResource
    {
        $user = $request->user();
        $old = $user->avatar_path;
        $user->forceFill(['avatar_path' => $request->file('logo')->store('avatars', 'public')])->save();
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return (new UserResource($user->refresh()))->withPermissions();
    }

    /** PUT /api/v1/me/password — `current_password`, `password`, `password_confirmation`. Les autres sessions sont fermées. */
    public function password(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(10)->letters()->numbers()],
        ], [
            'password.different' => 'Le nouveau mot de passe doit être différent de l\'actuel.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ], ['current_password' => 'mot de passe actuel', 'password' => 'nouveau mot de passe']);
        abort_unless(Hash::check($data['current_password'], $user->password), 422, 'Le mot de passe actuel est incorrect.');

        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $current = $user->currentAccessToken();
        $user->tokens()->when($current && method_exists($current, 'getKey'), fn ($q) => $q->whereKeyNot($current->getKey()))->delete();
        activity('users')->performedOn($user)->causedBy($user)->log('Mot de passe changé');

        return response()->json(['message' => 'Mot de passe changé. Vos autres appareils sont déconnectés.']);
    }
}
