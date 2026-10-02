<?php

namespace App\GraphQL\Validators;

use Nuwave\Lighthouse\Validation\Validator;

final class SubmitReviewResultValidator extends Validator
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'grammarPointId' => ['required'],
            'quality' => ['required', 'integer', 'between:0,5'],
        ];
    }
}
