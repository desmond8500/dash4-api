<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /** GET /api/v1/roles — les profils, leur type de compte et leurs permissions. */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $roles = Role::with('permissions')->orderBy('id')->get()->map(fn (Role $role) => [
            'name' => $role->name,
            'label' => Permissions::ROLE_LABELS[$role->name] ?? $role->name,
            'account_type' => $role->name === Permissions::ROLE_TECHNICIAN
                ? AccountType::Technician->value
                : AccountType::Manager->value,
            'permissions' => $role->permissions->pluck('name')->sort()->values(),
        ]);

        return response()->json(['data' => $roles]);
    }
}
