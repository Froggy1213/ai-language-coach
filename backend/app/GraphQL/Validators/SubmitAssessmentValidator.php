<?php

namespace App\GraphQL\Validators;

use Nuwave\Lighthouse\Validation\Validator;

final class SubmitAssessmentValidator extends Validator
{
    /**
     * @return array<int, mixed>
     */
    public function rules(): array
    {
        return [
            // Long enough for an S3 object URL, and no longer than the column
            // the pipeline stores it in.
            'audioUrl' => ['required', 'string', 'url', 'max:512'],
        ];
    }
}
