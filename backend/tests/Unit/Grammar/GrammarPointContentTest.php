<?php

namespace Tests\Unit\Grammar;

use App\Grammar\GrammarPointContent;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GrammarPointContentTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidEntries(): array
    {
        return [
            'missing level' => [self::entry(['level' => null])],
            'unknown level' => [self::entry(['level' => 'C2'])],
            'missing practice prompt' => [self::entry(['practice_prompt' => ''])],
            'cheat sheet is not an array' => [self::entry(['cheat_sheet' => 'see the book'])],
            'cheat sheet without examples' => [self::entry(['cheat_sheet' => [
                'rule' => 'Use it for habits.',
                'formula' => 'base verb',
                'pitfalls' => ['Mind the -s.'],
            ]])],
            'cheat sheet with an empty pitfall' => [self::entry(['cheat_sheet' => [
                'rule' => 'Use it for habits.',
                'formula' => 'base verb',
                'examples' => ['I work every day.'],
                'pitfalls' => [''],
            ]])],
        ];
    }

    #[DataProvider('invalidEntries')]
    public function test_it_rejects_a_malformed_entry(array $entry): void
    {
        $this->expectException(InvalidArgumentException::class);

        GrammarPointContent::fromArray('en', 'present_simple', $entry);
    }

    public function test_it_builds_a_cheat_sheet_that_matches_the_graphql_shape(): void
    {
        $content = GrammarPointContent::fromArray('en', 'present_simple', self::entry());

        $this->assertSame([
            'rule' => 'Use it for habits.',
            'formula' => 'base verb',
            'examples' => ['I work every day.'],
            'pitfalls' => ['Mind the -s.'],
        ], $content->cheatSheet->toArray());
        $this->assertSame('en', $content->language);
        $this->assertSame('present_simple', $content->code);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function entry(array $overrides = []): array
    {
        $entry = [
            'title' => 'Present Simple',
            'category' => 'tenses',
            'level' => 'A1',
            'cheat_sheet' => [
                'rule' => 'Use it for habits.',
                'formula' => 'base verb',
                'examples' => ['I work every day.'],
                'pitfalls' => ['Mind the -s.'],
            ],
            'practice_prompt' => 'Describe your weekday.',
        ];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($entry[$key]);

                continue;
            }

            $entry[$key] = $value;
        }

        return $entry;
    }
}
