<?php

namespace App\Mistakes;

use App\Models\User;
use App\Models\VoiceSession;
use App\Scenarios\ScenarioCatalogue;
use App\Scenarios\ScenarioContent;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\StructuredAgentResponse;

use function Laravel\Ai\agent;

/**
 * Reviews a scenario session against its learner goal with DeepSeek (plan §9).
 *
 * Deliberately a separate prompt from the mistake analysis: it judges whether
 * the learner communicated the thing the scenario asked for, not the grammar of
 * individual utterances. The scenario supplies the goal, the success criteria
 * and the phrases the learner could have used; the transcript is the learner's
 * side of the conversation only.
 *
 * Like the other LLM boundaries, the response is validated here: `laravel/ai`
 * appends the schema to the instructions but does not enforce it, so a reply
 * that does not decode silently becomes an empty array.
 */
final class DeepSeekGoalReviewer implements GoalReviewer
{
    /**
     * More than this and the list stops being useful feedback.
     */
    private const MAX_MISSED_EXPRESSIONS = 5;

    public function __construct(private readonly ScenarioCatalogue $catalogue) {}

    public function review(VoiceSession $session): GoalReviewResult
    {
        if ($session->scenario_code === null) {
            throw new GoalReviewFailed('The session is not a scenario session and has no goal to review.');
        }

        $user = $session->user;

        if (! $user instanceof User) {
            throw new GoalReviewFailed('The session has no learner to review.');
        }

        $scenario = $this->catalogue->findForUser($user, $session->scenario_code);

        if (! $scenario instanceof ScenarioContent) {
            throw new GoalReviewFailed("The scenario `{$session->scenario_code}` is not in the catalogue.");
        }

        $utterances = $session->learnerUtterances();

        if ($utterances === []) {
            throw new GoalReviewFailed('The session has no learner utterances to review.');
        }

        $response = agent($this->instructions($user, $scenario), schema: $this->schema())
            ->prompt(
                implode("\n", $utterances),
                [],
                (string) config('assessments.goal_review.provider'),
                (string) config('assessments.goal_review.model'),
                (int) config('assessments.goal_review.timeout_seconds'),
            );

        if (! $response instanceof StructuredAgentResponse) {
            throw new GoalReviewFailed('The goal review agent returned an unstructured response.');
        }

        return $this->validateAndBuildResult($response->structured);
    }

    private function instructions(User $user, ScenarioContent $scenario): string
    {
        $language = $user->targetLanguageName();

        $phrases = implode("\n", array_map(
            static fn (string $phrase): string => "- {$phrase}",
            $scenario->usefulPhrases,
        ));

        return <<<PROMPT
        You are an experienced {$language} teacher reviewing a learner's role-play after the conversation has ended.

        The scenario: {$scenario->title}
        The setting: {$scenario->setting}

        The learner's role: {$scenario->learnerRole}
        The learner's goal: {$scenario->learnerGoal}
        What success looks like: {$scenario->successCriteria}

        Context about the other person: {$scenario->aiGoal}

        Expressions the scenario suggested the learner could use:
        {$phrases}

        Decide whether the learner achieved their goal: did the conversation show them doing what the goal and the success criteria describe?

        Judge communication, not language quality. You are not a grammar checker: do not produce corrections, error lists, level judgements or comments on fluency or accent. A learner whose grammar was imperfect but who was understood and completed the task achieved the goal; a learner who was polite and fluent but did not do the task did not achieve it.

        Be concise, and be honest: do not reward a polite but incomplete performance, and do not fail a learner who reached the goal by an unusual route.

        Return:
        - goal_achieved: true only when the transcript supports the success criteria.
        - missed_expressions: up to five expressions the learner could have used but did not. Prefer the suggested expressions above, or a natural alternative that fits what the learner was trying to say. Return an empty list when nothing worthwhile was missed.
        PROMPT;
    }

    /**
     * @return Closure(JsonSchema): array<string, mixed>
     */
    private function schema(): Closure
    {
        return static fn (JsonSchema $schema): array => [
            'goal_achieved' => $schema->boolean()->required(),
            'missed_expressions' => $schema->array()->items($schema->string())->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $structured
     *
     * @throws GoalReviewFailed when the response fails validation.
     */
    private function validateAndBuildResult(array $structured): GoalReviewResult
    {
        try {
            $validated = Validator::make($structured, [
                'goal_achieved' => ['required', 'boolean'],
                'missed_expressions' => ['present', 'array'],
                'missed_expressions.*' => ['string'],
            ])->validate();
        } catch (ValidationException $exception) {
            throw new GoalReviewFailed(
                'The goal review model returned an unusable response.',
                previous: $exception,
            );
        }

        $missedExpressions = [];

        foreach ($validated['missed_expressions'] as $expression) {
            $expression = trim((string) $expression);

            if ($expression === '') {
                continue;
            }

            $missedExpressions[] = $expression;

            if (count($missedExpressions) === self::MAX_MISSED_EXPRESSIONS) {
                break;
            }
        }

        return new GoalReviewResult(
            goalAchieved: (bool) $validated['goal_achieved'],
            missedExpressions: $missedExpressions,
        );
    }
}
