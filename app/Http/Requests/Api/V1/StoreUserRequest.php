<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountType;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'firstname' => ['required', 'string', 'max:100'],
            'lastname' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'account_type' => ['required', new Enum(AccountType::class)],
            'password' => ['required', 'string', Password::min(10)->letters()->numbers()],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(array_keys(Permissions::ROLE_LABELS))],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                RoleRules::check(
                    $validator,
                    $this->user(),
                    AccountType::tryFrom((string) $this->input('account_type')),
                    (array) $this->input('roles', []),
                );
            },
        ];
    }
}
