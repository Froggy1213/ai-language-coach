<?php

namespace Tests\Feature\Grammar;

use App\Enums\CefrLevel;
use App\Grammar\GrammarCatalogue;
use App\Grammar\GrammarPointContent;
use Tests\TestCase;

class GrammarCatalogueTest extends TestCase
{
    public function test_the_english_catalogue_has_teachable_points(): void
    {
        $catalogue = $this->catalogue();

        $this->assertNotSame([], $catalogue->forLanguage('en'));
        $this->assertSame(CefrLevel::A1, $catalogue->find('en', 'present_simple')?->level);
        $this->assertNull($catalogue->find('en', 'not_a_grammar_point'));
    }

    public function test_every_entry_has_the_content_a_lesson_card_needs(): void
    {
        foreach ($this->catalogue()->forLanguage('en') as $content) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $content->code);
            $this->assertNotSame('', trim($content->title), "`{$content->code}` has no title.");
            $this->assertNotSame('', trim($content->category), "`{$content->code}` has no category.");
            $this->assertNotSame('', trim($content->cheatSheet->rule), "`{$content->code}` has no rule.");
            $this->assertNotSame('', trim($content->cheatSheet->formula), "`{$content->code}` has no formula.");
            $this->assertNotSame([], $content->cheatSheet->examples, "`{$content->code}` has no examples.");
            $this->assertNotSame([], $content->cheatSheet->pitfalls, "`{$content->code}` has no pitfalls.");
            $this->assertNotSame('', trim($content->practicePrompt), "`{$content->code}` has no practice prompt.");
        }
    }

    public function test_every_cefr_band_has_at_least_one_point(): void
    {
        $bands = array_unique(array_map(
            static fn (GrammarPointContent $content): string => $content->level->value,
            $this->catalogue()->forLanguage('en'),
        ));

        foreach (CefrLevel::cases() as $level) {
            $this->assertContains($level->value, $bands, "No grammar point is catalogued at {$level->value}.");
        }
    }

    public function test_up_to_stops_at_the_learners_band(): void
    {
        $contents = $this->catalogue()->upTo('en', CefrLevel::B1);
        $codes = array_map(static fn (GrammarPointContent $content): string => $content->code, $contents);

        $this->assertContains('present_simple', $codes, 'An A1 point is missing from a B1 roadmap.');
        $this->assertContains('second_conditional', $codes, 'A B1 point is missing from a B1 roadmap.');
        $this->assertNotContains('third_conditional', $codes, 'A B2 point leaked into a B1 roadmap.');
        $this->assertNotContains('mixed_conditionals', $codes, 'A C1 point leaked into a B1 roadmap.');

        $bands = array_unique(array_map(static fn (GrammarPointContent $content): string => $content->level->value, $contents));
        sort($bands);

        $this->assertSame(['A1', 'A2', 'B1'], $bands);
    }

    public function test_up_to_returns_the_weakest_bands_first(): void
    {
        $ranks = array_map(
            static fn (GrammarPointContent $content): int => $content->level->rank(),
            $this->catalogue()->upTo('en', CefrLevel::C1),
        );
        $weakestFirst = $ranks;
        sort($weakestFirst);

        $this->assertSame($weakestFirst, $ranks);
    }

    public function test_a_language_without_a_catalogue_has_no_points(): void
    {
        $catalogue = $this->catalogue();

        $this->assertSame([], $catalogue->forLanguage('xx'));
        $this->assertSame([], $catalogue->upTo('xx', CefrLevel::C1));
        $this->assertNull($catalogue->find('xx', 'present_simple'));
    }

    private function catalogue(): GrammarCatalogue
    {
        return $this->app->make(GrammarCatalogue::class);
    }
}
