<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountType;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Validation\Validator;

/** Règles communes sur les rôles demandés à la création ou à la modification d'un utilisateur. */
final class RoleRules
{
    public static function check(Validator $validator, User $actor, ?AccountType $type, array $roles): void
    {
        if (! $type) {
            return;
        }

        $allowed = Permissions::rolesForAccountType($type);
        $invalid = array_diff($roles, $allowed);

        if ($invalid !== []) {
            $validator->errors()->add('roles', $type === AccountType::Technician
                ? 'Un compte technicien ne peut avoir que le rôle Technicien.'
                : 'Le rôle Technicien est réservé aux comptes de type technicien.');
        }

        if (in_array(Permissions::ROLE_ADMIN, $roles, true) && ! $actor->isAdmin()) {
            $validator->errors()->add('roles', 'Seul un administrateur peut donner le rôle Administrateur.');
        }
    }
}
