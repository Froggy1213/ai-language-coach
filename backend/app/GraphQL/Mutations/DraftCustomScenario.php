<?php

namespace App\GraphQL\Mutations;

use App\Enums\CefrLevel;
use App\Models\CustomScenario;
use App\Models\User;
use App\Scenarios\ScenarioDrafter;
use App\Scenarios\ScenarioRegister;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Date;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Throwable;

final class DraftCustomScenario
{
    public function __construct(private readonly ScenarioDrafter $drafter) {}

    /**
     * @param  array{prompt: string, register?: string|null, level?: CefrLevel|string|null, code?: string|null}  $args
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $user = $context->user();
        assert($user instanceof User);

        $prompt = trim($args['prompt']);
        $registerValue = trim((string) ($args['register'] ?? ''));
        $register = $registerValue !== ''
            ? ScenarioRegister::from($registerValue)
            : ScenarioRegister::Neutral;

        $levelArg = $args['level'] ?? null;
        $level = match (true) {
            $levelArg instanceof CefrLevel => $levelArg,
            is_string($levelArg) && $levelArg !== '' => CefrLevel::from($levelArg),
            default => $user->current_level,
        };

        $existing = null;
        $codeArg = trim((string) ($args['code'] ?? ''));

        if ($codeArg !== '') {
            $existing = $user->customScenarios()
                ->where('language', $user->target_language)
                ->where('code', $codeArg)
                ->first();

            if (! $existing instanceof CustomScenario) {
                throw new Error(
                    'This scenario does not exist.',
                    extensions: ['code' => 'SCENARIO_NOT_FOUND'],
                );
            }
        }

        try {
            $draft = $this->drafter->draft($user, $prompt, $level, $register);
        } catch (Throwable $exception) {
            report($exception);

            throw new Error(
                'Could not draft the scenario. Please try again.',
                extensions: ['code' => 'SCENARIO_DRAFT_FAILED'],
                previous: $exception,
            );
        }

        // Prune stale unconfirmed drafts for this learner so abandoned attempts do not pile up.
        $user->customScenarios()
            ->whereNull('confirmed_at')
            ->where('updated_at', '<', Date::now()->subDay())
            ->when(
                $existing instanceof CustomScenario,
                static fn ($query) => $query->whereKeyNot($existing->getKey()),
            )
            ->delete();

        $attributes = [
            'language' => (string) $user->target_language,
            'user_prompt' => $prompt,
            'title' => $draft->title,
            'summary' => $draft->summary,
            'level' => $draft->level,
            'register' => $draft->register,
            'setting' => $draft->setting,
            'ai_role' => $draft->aiRole,
            'ai_goal' => $draft->aiGoal,
            'learner_role' => $draft->learnerRole,
            'learner_goal' => $draft->learnerGoal,
            'complications' => $draft->complications,
            'opening_line' => $draft->openingLine,
            'success_criteria' => $draft->successCriteria,
            'useful_phrases' => $draft->usefulPhrases,
            'confirmed_at' => null,
        ];

        if ($existing instanceof CustomScenario) {
            $existing->update($attributes);
            $scenario = $existing->fresh();
        } else {
            $scenario = $user->customScenarios()->create([
                ...$attributes,
                'code' => CustomScenario::generateCode(),
            ]);
        }

        assert($scenario instanceof CustomScenario);

        return $scenario->toScenarioContent()->toGraphQLArray();
    }
}
