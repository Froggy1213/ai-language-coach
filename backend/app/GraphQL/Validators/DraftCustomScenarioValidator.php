<?php

namespace App\GraphQL\Validators;

use App\Scenarios\ScenarioRegister;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class DraftCustomScenarioValidator extends Validator
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:3', 'max:500'],
            'register' => ['nullable', 'string', Rule::enum(ScenarioRegister::class)],
            'code' => ['nullable', 'string', 'max:64'],
        ];
    }
}
