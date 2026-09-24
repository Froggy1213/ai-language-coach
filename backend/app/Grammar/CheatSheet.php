<?php

namespace App\Grammar;

use InvalidArgumentException;

/**
 * Reference material for one grammar point, mirroring the `CheatSheet` type of
 * the GraphQL contract (plan §4).
 *
 * Kept as a value object rather than a raw array so that the three places that
 * touch the shape — the catalogue file, the seeder and lesson card creation —
 * cannot drift apart.
 */
final readonly class CheatSheet
{
    /**
     * @param  list<string>  $examples
     * @param  list<string>  $pitfalls
     */
    public function __construct(
        public string $rule,
        public string $formula,
        public array $examples,
        public array $pitfalls,
    ) {
        if ($examples === []) {
            throw new InvalidArgumentException('A cheat sheet needs at least one example.');
        }

        if ($pitfalls === []) {
            throw new InvalidArgumentException('A cheat sheet needs at least one pitfall.');
        }
    }

    /**
     * Build a cheat sheet from the `cheat_sheet` block of a catalogue entry.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $context): self
    {
        foreach (['rule', 'formula', 'examples', 'pitfalls'] as $key) {
            if (! isset($data[$key])) {
                throw new InvalidArgumentException("The cheat sheet of {$context} is missing `{$key}`.");
            }
        }

        return new self(
            rule: self::string($data['rule'], $context, 'rule'),
            formula: self::string($data['formula'], $context, 'formula'),
            examples: self::listOfStrings($data['examples'], $context, 'examples'),
            pitfalls: self::listOfStrings($data['pitfalls'], $context, 'pitfalls'),
        );
    }

    /**
     * The shape stored in `lesson_cards.cheat_sheet`.
     *
     * @return array{rule: string, formula: string, examples: list<string>, pitfalls: list<string>}
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'formula' => $this->formula,
            'examples' => $this->examples,
            'pitfalls' => $this->pitfalls,
        ];
    }

    private static function string(mixed $value, string $context, string $key): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("The `{$key}` of {$context} must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function listOfStrings(mixed $value, string $context, string $key): array
    {
        if (! is_array($value) || $value === []) {
            throw new InvalidArgumentException("The `{$key}` of {$context} must be a non-empty list.");
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException("Every entry in `{$key}` of {$context} must be a non-empty string.");
            }
        }

        return array_values($value);
    }
}
