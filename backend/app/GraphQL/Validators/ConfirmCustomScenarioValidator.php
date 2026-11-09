<?php

namespace App\GraphQL\Validators;

use App\Scenarios\ScenarioRegister;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ConfirmCustomScenarioValidator extends Validator
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'input.code' => ['required', 'string', 'max:64'],
            'input.title' => ['required', 'string', 'max:160'],
            'input.summary' => ['required', 'string', 'max:400'],
            'input.register' => ['required', 'string', Rule::enum(ScenarioRegister::class)],
            'input.setting' => ['required', 'string', 'max:400'],
            'input.aiRole' => ['required', 'string', 'max:250'],
            'input.learnerRole' => ['required', 'string', 'max:250'],
            'input.learnerGoal' => ['required', 'string', 'max:250'],
            'input.usefulPhrases' => ['required', 'array', 'min:3', 'max:5'],
            'input.usefulPhrases.*' => ['required', 'string', 'max:200'],
        ];
    }
}
