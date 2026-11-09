<?php

namespace App\Scenarios;

use App\Enums\CefrLevel;
use App\Models\User;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\StructuredAgentResponse;

use function Laravel\Ai\agent;

/**
 * Expands a learner's short scenario description into a full role-play card
 * with DeepSeek.
 *
 * The learner may describe the situation in Japanese or in their target
 * language; the generated card is always written in the target language so it
 * matches the built-in catalogue and the voice agent's prompt contract.
 */
final class DeepSeekScenarioDrafter implements ScenarioDrafter
{
    private const MIN_COMPLICATIONS = 2;

    private const MAX_COMPLICATIONS = 4;

    private const MIN_USEFUL_PHRASES = 3;

    private const MAX_USEFUL_PHRASES = 5;

    public function draft(
        User $user,
        string $userPrompt,
        CefrLevel $level,
        ScenarioRegister $register,
    ): ScenarioDraft {
        $prompt = trim($userPrompt);

        if ($prompt === '') {
            throw new ScenarioDraftFailed('The scenario prompt cannot be empty.');
        }

        $response = agent($this->instructions($user, $level, $register), schema: $this->schema())
            ->prompt(
                $prompt,
                [],
                (string) config('assessments.scenario_draft.provider'),
                (string) config('assessments.scenario_draft.model'),
                (int) config('assessments.scenario_draft.timeout_seconds'),
            );

        if (! $response instanceof StructuredAgentResponse) {
            throw new ScenarioDraftFailed('The scenario draft agent returned an unstructured response.');
        }

        return $this->validateAndBuildDraft($response->structured, $level, $register);
    }

    private function instructions(User $user, CefrLevel $level, ScenarioRegister $register): string
    {
        $language = $user->targetLanguageName();

        return <<<PROMPT
        You are an experienced {$language} curriculum designer creating a realistic spoken role-play scenario for a learner at CEFR level {$level->value} with a {$register->value} tone.

        The learner will describe the situation they want to practise (their description may be in Japanese or in {$language}). Turn it into a complete, concrete role-play card written entirely in {$language}.

        Rules for a strong role-play scenario:
        1. Write every field in {$language}, pitched at CEFR {$level->value} and a {$register->value} register.
        2. Keep fields concise so the card fits in a compact dispatch payload: each text field should be one or two short sentences.
        3. Give the AI its own concrete objective (`ai_goal`) and 2 to 4 realistic `complications` (mild pushback, a constraint, a follow-up question, or an unexpected detail) so the conversation does not end in two turns.
        4. `opening_line` must be the exact first sentence the AI speaks in character to open the scene — never meta-instructions or "Let's role-play".
        5. `useful_phrases` must contain 3 to 5 natural {$language} expressions the learner can actually say during the scene to achieve `learner_goal`.
        6. `success_criteria` must state observable communicative outcomes that show the learner achieved `learner_goal`.
        PROMPT;
    }

    /**
     * @return Closure(JsonSchema): array<string, mixed>
     */
    private function schema(): Closure
    {
        return static fn (JsonSchema $schema): array => [
            'title' => $schema->string()->required(),
            'summary' => $schema->string()->required(),
            'setting' => $schema->string()->required(),
            'ai_role' => $schema->string()->required(),
            'ai_goal' => $schema->string()->required(),
            'learner_role' => $schema->string()->required(),
            'learner_goal' => $schema->string()->required(),
            'complications' => $schema->array()->items($schema->string())->required(),
            'opening_line' => $schema->string()->required(),
            'success_criteria' => $schema->string()->required(),
            'useful_phrases' => $schema->array()->items($schema->string())->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function validateAndBuildDraft(
        array $structured,
        CefrLevel $level,
        ScenarioRegister $register,
    ): ScenarioDraft {
        try {
            $validated = Validator::make($structured, [
                'title' => ['required', 'string'],
                'summary' => ['required', 'string'],
                'setting' => ['required', 'string'],
                'ai_role' => ['required', 'string'],
                'ai_goal' => ['required', 'string'],
                'learner_role' => ['required', 'string'],
                'learner_goal' => ['required', 'string'],
                'complications' => ['required', 'array'],
                'complications.*' => ['string'],
                'opening_line' => ['required', 'string'],
                'success_criteria' => ['required', 'string'],
                'useful_phrases' => ['required', 'array'],
                'useful_phrases.*' => ['string'],
            ])->validate();
        } catch (ValidationException $exception) {
            throw new ScenarioDraftFailed(
                'The scenario draft model returned an unusable response.',
                previous: $exception,
            );
        }

        $title = $this->boundedText($validated['title'], 'title', 140);
        $summary = $this->boundedText($validated['summary'], 'summary', 260);
        $setting = $this->boundedText($validated['setting'], 'setting', 260);
        $aiRole = $this->boundedText($validated['ai_role'], 'ai_role', 200);
        $aiGoal = $this->boundedText($validated['ai_goal'], 'ai_goal', 220);
        $learnerRole = $this->boundedText($validated['learner_role'], 'learner_role', 200);
        $learnerGoal = $this->boundedText($validated['learner_goal'], 'learner_goal', 220);
        $openingLine = $this->boundedText($validated['opening_line'], 'opening_line', 220);
        $successCriteria = $this->boundedText($validated['success_criteria'], 'success_criteria', 260);

        $complications = $this->boundedList(
            $validated['complications'],
            'complications',
            self::MIN_COMPLICATIONS,
            self::MAX_COMPLICATIONS,
            180,
        );

        $usefulPhrases = $this->boundedList(
            $validated['useful_phrases'],
            'useful_phrases',
            self::MIN_USEFUL_PHRASES,
            self::MAX_USEFUL_PHRASES,
            160,
        );

        return new ScenarioDraft(
            title: $title,
            summary: $summary,
            level: $level,
            register: $register,
            setting: $setting,
            aiRole: $aiRole,
            aiGoal: $aiGoal,
            learnerRole: $learnerRole,
            learnerGoal: $learnerGoal,
            complications: $complications,
            openingLine: $openingLine,
            successCriteria: $successCriteria,
            usefulPhrases: $usefulPhrases,
        );
    }

    private function boundedText(mixed $value, string $field, int $maxLength): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            throw new ScenarioDraftFailed("The scenario draft field `{$field}` was empty.");
        }

        return Str::limit($text, $maxLength, '');
    }

    /**
     * @param  array<mixed>  $items
     * @return list<string>
     */
    private function boundedList(
        array $items,
        string $field,
        int $min,
        int $max,
        int $maxItemLength,
    ): array {
        $result = [];

        foreach ($items as $item) {
            $text = Str::limit(trim((string) $item), $maxItemLength, '');

            if ($text === '') {
                continue;
            }

            $result[] = $text;

            if (count($result) === $max) {
                break;
            }
        }

        if (count($result) < $min) {
            throw new ScenarioDraftFailed("The scenario draft field `{$field}` needs at least {$min} entries.");
        }

        return $result;
    }
}
