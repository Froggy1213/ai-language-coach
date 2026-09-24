<?php

namespace App\GraphQL\Validators;

use App\Rules\SupportedAudioContentType;
use Nuwave\Lighthouse\Validation\Validator;

final class CreateAssessmentUploadUrlValidator extends Validator
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // The recorder reports its codec as a parameter
            // (`audio/webm;codecs=opus`), so the rule matches the base type.
            'contentType' => ['required', 'string', new SupportedAudioContentType],
        ];
    }
}
