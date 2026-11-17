<?php

namespace App\GraphQL\Validators;

use Nuwave\Lighthouse\Validation\Validator;

final class VerifyEmailValidator extends Validator
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'id' => ['required'],
            'hash' => ['required', 'string'],
            'expires' => ['required', 'integer'],
            'signature' => ['required', 'string'],
        ];
    }
}
