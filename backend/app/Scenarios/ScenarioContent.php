<?php

namespace App\Scenarios;

use App\Enums\CefrLevel;
use InvalidArgumentException;

/**
 * One role-play scenario as the catalogue declares it: the setting, both roles
 * and goals, the resistance the AI is allowed to introduce, and the material
 * the topic list and the hint panel need (plan §5).
 *
 * `voice_sessions.scenario_code` stores only the code, so a scenario that is
 * edited or removed never breaks session history; this is the content behind
 * that code, versioned per language in `resources/scenarios`.
 */
final readonly class ScenarioContent
{
    /**
     * Fields that describe the scene in text and must be present and non-empty.
     */
    private const REQUIRED_TEXT_FIELDS = [
        'title',
        'summary',
        'setting',
        'ai_role',
        'ai_goal',
        'learner_role',
        'learner_goal',
        'opening_line',
        'success_criteria',
    ];

    /**
     * @param  list<string>  $complications
     * @param  list<string>  $usefulPhrases
     */
    private function __construct(
        public string $language,
        public string $code,
        public string $title,
        public string $summary,
        public CefrLevel $level,
        public ScenarioRegister $register,
        public string $setting,
        public string $aiRole,
        public string $aiGoal,
        public string $learnerRole,
        public string $learnerGoal,
        public array $complications,
        public string $openingLine,
        public string $successCriteria,
        public array $usefulPhrases,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $language, string $code, array $data): self
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $code) !== 1) {
            throw new InvalidArgumentException("The scenario code `{$code}` for `{$language}` must match `^[a-z][a-z0-9_]*$`.");
        }

        $context = "scenario `{$language}.{$code}`";

        foreach ([...self::REQUIRED_TEXT_FIELDS, 'level', 'register', 'complications', 'useful_phrases'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new InvalidArgumentException("The {$context} is missing `{$key}`.");
            }
        }

        $level = is_string($data['level'])
            ? CefrLevel::tryFrom($data['level'])
            : null;

        if ($level === null) {
            throw new InvalidArgumentException("The {$context} has an unknown CEFR level; expected one of A1, A2, B1, B2, C1.");
        }

        $register = is_string($data['register'])
            ? ScenarioRegister::tryFrom($data['register'])
            : null;

        if ($register === null) {
            throw new InvalidArgumentException("The {$context} has an unknown register; expected one of formal, casual, neutral.");
        }

        $complications = self::listOfStrings($data['complications'], $context, 'complications');

        if (count($complications) < 2) {
            throw new InvalidArgumentException("The `complications` of the {$context} must list at least two ways the AI can introduce friction.");
        }

        $usefulPhrases = self::listOfStrings($data['useful_phrases'], $context, 'useful_phrases');

        if (count($usefulPhrases) < 3 || count($usefulPhrases) > 5) {
            throw new InvalidArgumentException("The `useful_phrases` of the {$context} must contain between three and five phrases.");
        }

        return new self(
            language: $language,
            code: $code,
            title: self::text($data['title'], $context, 'title'),
            summary: self::text($data['summary'], $context, 'summary'),
            level: $level,
            register: $register,
            setting: self::text($data['setting'], $context, 'setting'),
            aiRole: self::text($data['ai_role'], $context, 'ai_role'),
            aiGoal: self::text($data['ai_goal'], $context, 'ai_goal'),
            learnerRole: self::text($data['learner_role'], $context, 'learner_role'),
            learnerGoal: self::text($data['learner_goal'], $context, 'learner_goal'),
            complications: $complications,
            openingLine: self::text($data['opening_line'], $context, 'opening_line'),
            successCriteria: self::text($data['success_criteria'], $context, 'success_criteria'),
            usefulPhrases: $usefulPhrases,
        );
    }

    /**
     * The shape stored on a voice session and handed to the agent dispatcher.
     *
     * @return array{
     *     language: string,
     *     code: string,
     *     title: string,
     *     summary: string,
     *     level: string,
     *     register: string,
     *     setting: string,
     *     ai_role: string,
     *     ai_goal: string,
     *     learner_role: string,
     *     learner_goal: string,
     *     complications: list<string>,
     *     opening_line: string,
     *     success_criteria: string,
     *     useful_phrases: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'language' => $this->language,
            'code' => $this->code,
            'title' => $this->title,
            'summary' => $this->summary,
            'level' => $this->level->value,
            'register' => $this->register->value,
            'setting' => $this->setting,
            'ai_role' => $this->aiRole,
            'ai_goal' => $this->aiGoal,
            'learner_role' => $this->learnerRole,
            'learner_goal' => $this->learnerGoal,
            'complications' => $this->complications,
            'opening_line' => $this->openingLine,
            'success_criteria' => $this->successCriteria,
            'useful_phrases' => $this->usefulPhrases,
        ];
    }

    private static function text(mixed $value, string $context, string $key): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("The `{$key}` of the {$context} must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function listOfStrings(mixed $value, string $context, string $key): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new InvalidArgumentException("The `{$key}` of the {$context} must be a non-empty list of strings.");
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException("Every entry in `{$key}` of the {$context} must be a non-empty string.");
            }
        }

        return $value;
    }
}
