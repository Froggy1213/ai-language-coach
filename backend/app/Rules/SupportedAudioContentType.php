<?php

namespace App\Rules;

use App\Assessments\AudioContentType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SupportedAudioContentType implements ValidationRule
{
    /**
     * @param  Closure(string, string|null): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! AudioContentType::isAllowed($value)) {
            /** @var list<string> $allowed */
            $allowed = config('assessments.allowed_mime_types', []);

            $fail('The :attribute must be one of: '.implode(', ', $allowed).'.');
        }
    }
}
