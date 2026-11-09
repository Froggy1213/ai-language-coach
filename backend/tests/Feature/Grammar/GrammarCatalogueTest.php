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
        $entries = $catalogue->forLanguage('en');

        $this->assertCount(38, $entries);
        $this->assertSame('present_simple', $entries[0]->code);
        $this->assertSame(CefrLevel::A1, $catalogue->find('en', 'present_simple')?->level);
        $this->assertNull($catalogue->find('en', 'not_a_grammar_point'));
    }

    public function test_the_english_catalogue_forms_a_valid_topologically_sorted_dag(): void
    {
        $catalogue = $this->catalogue();
        $entries = $catalogue->forLanguage('en');

        $byCode = [];
        $positions = [];
        $roots = [];

        foreach ($entries as $index => $content) {
            $byCode[$content->code] = $content;
            $positions[$content->code] = $index;

            if ($content->prerequisites === []) {
                $roots[] = $content->code;
                $this->assertSame(
                    CefrLevel::A1,
                    $content->level,
                    "Root node `{$content->code}` must be at A1."
                );
            }
        }

        $this->assertSame(
            ['present_simple', 'be_and_there', 'plurals_countability'],
            $roots,
            'Expected the three foundational A1 roots in teaching order.'
        );

        foreach ($entries as $content) {
            foreach ($content->prerequisites as $prereqCode) {
                $this->assertArrayHasKey(
                    $prereqCode,
                    $byCode,
                    "`{$content->code}` references unknown prerequisite `{$prereqCode}`."
                );

                $prereq = $byCode[$prereqCode];

                $this->assertLessThanOrEqual(
                    $content->level->rank(),
                    $prereq->level->rank(),
                    "`{$content->code}` ({$content->level->value}) depends on higher-level `{$prereqCode}` ({$prereq->level->value})."
                );

                $this->assertLessThan(
                    $positions[$content->code],
                    $positions[$prereqCode],
                    "Prerequisite `{$prereqCode}` must appear before `{$content->code}` in topological order."
                );
            }
        }
    }

    public function test_it_exposes_prerequisites_for_a_point_and_as_a_full_map(): void
    {
        $catalogue = $this->catalogue();

        $this->assertSame([], $catalogue->prerequisitesOf('en', 'present_simple'));
        $this->assertSame(['present_simple', 'be_and_there'], $catalogue->prerequisitesOf('en', 'question_forms'));
        $this->assertSame(['passive_present_past', 'gerunds_infinitives'], $catalogue->prerequisitesOf('en', 'causative_make_let'));
        $this->assertSame([], $catalogue->prerequisitesOf('en', 'not_a_grammar_point'));

        $map = $catalogue->prerequisiteMap('en');

        $this->assertCount(38, $map);
        $this->assertSame([], $map['present_simple']);
        $this->assertSame(['third_conditional', 'wish_if_only'], $map['mixed_conditionals']);
        $this->assertSame([], $catalogue->prerequisiteMap('xx'));
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

    public function test_path_traversal_language_code_returns_empty_array(): void
    {
        $catalogue = $this->catalogue();

        $this->assertSame([], $catalogue->forLanguage('../../../etc/passwd'));
        $this->assertSame([], $catalogue->forLanguage('en/../../config'));
        $this->assertSame([], $catalogue->upTo('../malicious', CefrLevel::C1));
        $this->assertNull($catalogue->find('../secret', 'present_simple'));
    }

    private function catalogue(): GrammarCatalogue
    {
        return $this->app->make(GrammarCatalogue::class);
    }
}
