<?php

namespace App\GraphQL\Validators;

use Nuwave\Lighthouse\Validation\Validator;

final class UpdatePasswordValidator extends Validator
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:8'],
        ];
    }
}
