<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /** GET /api/v1/users — filtres : search, account_type, is_active, role. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with('roles')
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q->where('firstname', 'like', $term)
                    ->orWhere('lastname', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->when($request->filled('account_type'), fn ($q) => $q->where('account_type', $request->string('account_type')))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when(
                array_key_exists((string) $request->string('role'), Permissions::ROLE_LABELS),
                fn ($q) => $q->role((string) $request->string('role')),
            )
            ->orderBy('lastname')
            ->orderBy('firstname')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return UserResource::collection($users);
    }

    /** POST /api/v1/users */
    public function store(StoreUserRequest $request): UserResource
    {
        $this->authorize('create', User::class);

        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->safe()->except('roles'));
            $user->syncRoles($request->validated('roles'));

            $this->logRoles($request->user(), $user, [], $user->getRoleNames()->all());

            return $user;
        });

        return new UserResource($user->load('roles'));
    }

    /** GET /api/v1/users/{user} */
    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        return new UserResource($user->load('roles'));
    }

    /** PATCH /api/v1/users/{user} — désactivation : is_active = false (pas de suppression). */
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $this->authorize('update', $user);

        $data = $request->safe()->except('roles');
        $newRoles = $request->has('roles') ? $request->validated('roles') : null;

        $this->ensureAnAdminRemains($user, $data, $newRoles);

        DB::transaction(function () use ($request, $user, $data, $newRoles) {
            $accountTypeChanged = array_key_exists('account_type', $data)
                && $data['account_type'] !== $user->account_type->value;
            $deactivated = array_key_exists('is_active', $data) && ! $data['is_active'];

            $user->update($data);

            if ($newRoles !== null) {
                $oldRoles = $user->getRoleNames()->all();
                $user->syncRoles($newRoles);
                $this->logRoles($request->user(), $user, $oldRoles, $newRoles);
            }

            // Changement de type de compte ou désactivation : on force une nouvelle connexion
            // (l'app mobile efface alors ses données locales).
            if ($accountTypeChanged || $deactivated) {
                $user->tokens()->delete();
            }
        });

        return new UserResource($user->refresh()->load('roles'));
    }

    /** Il doit toujours rester au moins un administrateur actif. */
    private function ensureAnAdminRemains(User $user, array $data, ?array $newRoles): void
    {
        if (! $user->isAdmin() || ! $user->is_active) {
            return;
        }

        $losesAdmin = ($newRoles !== null && ! in_array(Permissions::ROLE_ADMIN, $newRoles, true))
            || (array_key_exists('is_active', $data) && ! $data['is_active']);

        if (! $losesAdmin) {
            return;
        }

        $otherActiveAdmins = User::role(Permissions::ROLE_ADMIN)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->count();

        if ($otherActiveAdmins === 0) {
            throw ValidationException::withMessages([
                'roles' => ['Il doit rester au moins un administrateur actif.'],
            ]);
        }
    }

    private function logRoles(User $actor, User $user, array $old, array $new): void
    {
        sort($old);
        sort($new);

        if ($old === $new) {
            return;
        }

        activity('users')
            ->performedOn($user)
            ->causedBy($actor)
            ->event('roles_updated')
            ->withProperties(['old' => $old, 'new' => $new])
            ->log('Rôles modifiés');
    }
}
