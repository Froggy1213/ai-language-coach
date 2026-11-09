<?php

namespace App\Grammar;

use App\Enums\CefrLevel;
use InvalidArgumentException;

/**
 * One teachable grammar point as the catalogue declares it: the CEFR band it
 * belongs to, the cheat sheet and the practice prompt.
 *
 * `grammar_points` holds the id-bearing row that `mistakes` points at; this is
 * the content behind it, versioned per language in `resources/grammar`.
 */
final readonly class GrammarPointContent
{
    /**
     * @param  list<string>  $prerequisites
     */
    private function __construct(
        public string $language,
        public string $code,
        public string $title,
        public string $category,
        public CefrLevel $level,
        public CheatSheet $cheatSheet,
        public string $practicePrompt,
        public array $prerequisites = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $language, string $code, array $data): self
    {
        $context = "grammar point `{$language}.{$code}`";

        foreach (['title', 'category', 'level', 'cheat_sheet', 'practice_prompt'] as $key) {
            if (! isset($data[$key])) {
                throw new InvalidArgumentException("The {$context} is missing `{$key}`.");
            }
        }

        $level = is_string($data['level'])
            ? CefrLevel::tryFrom($data['level'])
            : null;

        if ($level === null) {
            throw new InvalidArgumentException("The {$context} has an unknown CEFR level; expected one of A1, A2, B1, B2, C1.");
        }

        if (! is_array($data['cheat_sheet'])) {
            throw new InvalidArgumentException("The `cheat_sheet` of the {$context} must be an array.");
        }

        if (! is_string($data['practice_prompt']) || trim($data['practice_prompt']) === '') {
            throw new InvalidArgumentException("The `practice_prompt` of the {$context} must be a non-empty string.");
        }

        $prerequisites = array_key_exists('prerequisites', $data)
            ? self::prerequisites($data['prerequisites'], $code, $context)
            : [];

        return new self(
            language: $language,
            code: $code,
            title: self::text($data['title'], $context, 'title'),
            category: self::text($data['category'], $context, 'category'),
            level: $level,
            cheatSheet: CheatSheet::fromArray($data['cheat_sheet'], $context),
            practicePrompt: $data['practice_prompt'],
            prerequisites: $prerequisites,
        );
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
    private static function prerequisites(mixed $value, string $code, string $context): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("The `prerequisites` of the {$context} must be a list of grammar point codes.");
        }

        $seen = [];

        foreach ($value as $prerequisite) {
            if (! is_string($prerequisite) || preg_match('/^[a-z][a-z0-9_]*$/', $prerequisite) !== 1) {
                throw new InvalidArgumentException("Every entry in `prerequisites` of the {$context} must be a valid grammar point code.");
            }

            if ($prerequisite === $code) {
                throw new InvalidArgumentException("The {$context} cannot list itself in `prerequisites`.");
            }

            if (isset($seen[$prerequisite])) {
                throw new InvalidArgumentException("The {$context} lists duplicate prerequisite `{$prerequisite}`.");
            }

            $seen[$prerequisite] = true;
        }

        return array_values($value);
    }
}
