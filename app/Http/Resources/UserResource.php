<?php

namespace App\Http\Resources;

use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /** Ajouter la liste des permissions effectives (pour /auth/me et la connexion). */
    private bool $withPermissions = false;

    public function withPermissions(): static
    {
        $this->withPermissions = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'job_title' => $this->job_title,
            'avatar_url' => $this->avatar_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->avatar_path) : null,
            'account_type' => [
                'value' => $this->account_type->value,
                'label' => $this->account_type->label(),
            ],
            'is_active' => $this->is_active,
            'roles' => $this->getRoleNames()->map(fn (string $name) => [
                'name' => $name,
                'label' => Permissions::ROLE_LABELS[$name] ?? $name,
            ])->values(),
            'permissions' => $this->when($this->withPermissions, fn () => $this->effectivePermissions()),
            // Préférences : seulement pour la personne connectée
            'preferences' => $this->when($this->withPermissions, fn () => \App\Support\Preferences::for($this->resource)),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
