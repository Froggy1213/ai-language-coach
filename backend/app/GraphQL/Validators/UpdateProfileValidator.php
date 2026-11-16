<?php

namespace App\GraphQL\Validators;

use Illuminate\Auth\AuthManager;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class UpdateProfileValidator extends Validator
{
    public function __construct(
        private readonly AuthManager $auth,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $userId = $this->auth->guard('sanctum')->id() ?? $this->auth->id();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
        ];
    }
}
