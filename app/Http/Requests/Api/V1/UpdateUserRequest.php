<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountType;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'firstname' => ['sometimes', 'required', 'string', 'max:100'],
            'lastname' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:150'],
            'account_type' => ['sometimes', 'required', new Enum(AccountType::class)],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'required', 'string', Password::min(10)->letters()->numbers()],
            'roles' => ['sometimes', 'required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(array_keys(Permissions::ROLE_LABELS))],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $user = $this->route('user');
                $actor = $this->user();

                if ($actor->is($user) && ($this->has('roles') || $this->has('is_active') || $this->has('account_type'))) {
                    $validator->errors()->add('roles', 'Vous ne pouvez pas modifier vos propres rôles, type de compte ou statut.');

                    return;
                }

                if ($this->has('roles') || $this->has('account_type')) {
                    RoleRules::check(
                        $validator,
                        $actor,
                        $this->has('account_type')
                            ? AccountType::tryFrom((string) $this->input('account_type'))
                            : $user->account_type,
                        $this->has('roles') ? (array) $this->input('roles') : $user->getRoleNames()->all(),
                    );
                }
            },
        ];
    }
}
