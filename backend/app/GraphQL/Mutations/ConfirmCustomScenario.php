<?php

namespace App\GraphQL\Mutations;

use App\Enums\CefrLevel;
use App\Models\CustomScenario;
use App\Models\User;
use App\Scenarios\ScenarioRegister;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class ConfirmCustomScenario
{
    /**
     * @param  array{input: array<string, mixed>}  $args
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $user = $context->user();
        assert($user instanceof User);

        $input = $args['input'];
        $code = trim((string) $input['code']);

        $scenario = $user->customScenarios()
            ->where('language', $user->target_language)
            ->where('code', $code)
            ->first();

        if (! $scenario instanceof CustomScenario) {
            throw new Error(
                'This scenario does not exist.',
                extensions: ['code' => 'SCENARIO_NOT_FOUND'],
            );
        }

        $levelArg = $input['level'];
        $level = $levelArg instanceof CefrLevel
            ? $levelArg
            : CefrLevel::from((string) $levelArg);

        $register = ScenarioRegister::from(trim((string) $input['register']));

        $phrases = [];
        foreach ((array) $input['usefulPhrases'] as $phrase) {
            $trimmed = Str::limit(trim((string) $phrase), 160, '');
            if ($trimmed !== '') {
                $phrases[] = $trimmed;
            }
        }

        if (count($phrases) < 3 || count($phrases) > 5) {
            throw ValidationException::withMessages([
                'input.usefulPhrases' => 'Provide between three and five non-empty useful phrases.',
            ]);
        }

        $title = $this->requireText($input['title'], 'input.title', 140);
        $summary = $this->requireText($input['summary'], 'input.summary', 260);
        $setting = $this->requireText($input['setting'], 'input.setting', 260);
        $aiRole = $this->requireText($input['aiRole'], 'input.aiRole', 200);
        $learnerRole = $this->requireText($input['learnerRole'], 'input.learnerRole', 200);
        $learnerGoal = $this->requireText($input['learnerGoal'], 'input.learnerGoal', 220);

        $scenario->update([
            'title' => $title,
            'summary' => $summary,
            'level' => $level,
            'register' => $register,
            'setting' => $setting,
            'ai_role' => $aiRole,
            'learner_role' => $learnerRole,
            'learner_goal' => $learnerGoal,
            'useful_phrases' => $phrases,
            'confirmed_at' => Date::now(),
        ]);

        return $scenario->fresh()->toScenarioContent()->toGraphQLArray();
    }

    private function requireText(mixed $value, string $field, int $maxLength): string
    {
        $text = Str::limit(trim((string) $value), $maxLength, '');

        if ($text === '') {
            throw ValidationException::withMessages([
                $field => 'This field cannot be blank.',
            ]);
        }

        return $text;
    }
}
