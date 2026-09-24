<?php

namespace App\Grammar;

use App\Enums\CefrLevel;
use RuntimeException;

/**
 * Versioned reference content for grammar points (plan §5).
 *
 * `resources/grammar/{language}.php` carries the CEFR band, the cheat sheet and
 * the practice prompt; `grammar_points` carries the id-bearing rows that
 * `mistakes` points at. GrammarPointSeeder copies one into the other, and
 * RoadmapGenerator reads both.
 *
 * The catalogue is deliberately not in the database: it is content, so it is
 * reviewed and versioned like code. A language without a file simply has no
 * teachable points yet — only its `uncategorized` sentinel.
 */
final class GrammarCatalogue
{
    /**
     * @var array<string, array<string, GrammarPointContent>>
     */
    private array $languages = [];

    /**
     * Every catalogue entry for a language, in teaching order.
     *
     * @return list<GrammarPointContent>
     */
    public function forLanguage(string $language): array
    {
        return array_values($this->entries($language));
    }

    public function find(string $language, string $code): ?GrammarPointContent
    {
        return $this->entries($language)[$code] ?? null;
    }

    /**
     * Everything a learner at the given band should work through: that band and
     * every weaker one, weakest first.
     *
     * @return list<GrammarPointContent>
     */
    public function upTo(string $language, CefrLevel $level): array
    {
        $entries = $this->entries($language);

        // The file is written in teaching order, and usort() is stable, so
        // points of the same band keep the order their author gave them.
        usort($entries, static fn (GrammarPointContent $a, GrammarPointContent $b): int => $a->level->rank() <=> $b->level->rank());

        return array_values(array_filter(
            $entries,
            static fn (GrammarPointContent $content): bool => $content->level->isAtMost($level),
        ));
    }

    /**
     * @return array<string, GrammarPointContent>
     */
    private function entries(string $language): array
    {
        if (isset($this->languages[$language])) {
            return $this->languages[$language];
        }

        $path = resource_path("grammar/{$language}.php");

        if (! is_file($path)) {
            return $this->languages[$language] = [];
        }

        $data = require $path;

        if (! is_array($data)) {
            throw new RuntimeException("The grammar catalogue at {$path} must return an array.");
        }

        $entries = [];

        foreach ($data as $code => $entry) {
            if (! is_string($code) || ! is_array($entry)) {
                throw new RuntimeException("The grammar catalogue at {$path} must map a string code to an array of content.");
            }

            $entries[$code] = GrammarPointContent::fromArray($language, $code, $entry);
        }

        return $this->languages[$language] = $entries;
    }
}
