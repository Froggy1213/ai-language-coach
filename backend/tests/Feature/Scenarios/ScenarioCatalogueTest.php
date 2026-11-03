<?php

namespace Tests\Feature\Scenarios;

use App\Enums\CefrLevel;
use App\Scenarios\ScenarioCatalogue;
use App\Scenarios\ScenarioContent;
use App\Scenarios\ScenarioRegister;
use Tests\TestCase;

class ScenarioCatalogueTest extends TestCase
{
    /**
     * The topic list order the plan asks for (plan §5).
     *
     * @var list<string>
     */
    private const CODES = [
        'free_talk',
        'airport_checkin',
        'business_meeting',
        'client_call',
        'job_interview',
        'small_talk_before_meeting',
    ];

    public function test_the_english_catalogue_lists_the_six_scenarios_in_order(): void
    {
        $contents = $this->catalogue()->forLanguage('en');

        $this->assertSame(self::CODES, array_map(
            static fn (ScenarioContent $content): string => $content->code,
            $contents,
        ));
    }

    public function test_every_entry_has_the_content_a_scenario_session_needs(): void
    {
        foreach ($this->catalogue()->forLanguage('en') as $content) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $content->code);
            $this->assertContains($content->level, CefrLevel::cases(), "`{$content->code}` has an unknown level.");
            $this->assertContains($content->register, ScenarioRegister::cases(), "`{$content->code}` has an unknown register.");

            foreach ([
                'title' => $content->title,
                'summary' => $content->summary,
                'setting' => $content->setting,
                'ai role' => $content->aiRole,
                'ai goal' => $content->aiGoal,
                'learner role' => $content->learnerRole,
                'learner goal' => $content->learnerGoal,
                'opening line' => $content->openingLine,
                'success criteria' => $content->successCriteria,
            ] as $field => $value) {
                $this->assertNotSame('', trim($value), "`{$content->code}` has an empty {$field}.");
            }

            $this->assertGreaterThanOrEqual(2, count($content->complications), "`{$content->code}` needs at least two complications.");

            foreach ($content->complications as $index => $complication) {
                $this->assertNotSame('', trim($complication), "`{$content->code}` has an empty complication at index {$index}.");
            }

            $this->assertGreaterThanOrEqual(3, count($content->usefulPhrases), "`{$content->code}` needs at least three useful phrases.");
            $this->assertLessThanOrEqual(5, count($content->usefulPhrases), "`{$content->code}` has more than five useful phrases.");

            foreach ($content->usefulPhrases as $index => $phrase) {
                $this->assertNotSame('', trim($phrase), "`{$content->code}` has an empty useful phrase at index {$index}.");
            }
        }
    }

    public function test_the_topic_list_gets_the_level_and_register_the_plan_asks_for(): void
    {
        foreach ([
            'free_talk' => [CefrLevel::A2, ScenarioRegister::Neutral],
            'airport_checkin' => [CefrLevel::A2, ScenarioRegister::Formal],
            'business_meeting' => [CefrLevel::B1, ScenarioRegister::Formal],
            'client_call' => [CefrLevel::B1, ScenarioRegister::Formal],
            'job_interview' => [CefrLevel::B1, ScenarioRegister::Formal],
            'small_talk_before_meeting' => [CefrLevel::A2, ScenarioRegister::Neutral],
        ] as $code => [$level, $register]) {
            $content = $this->catalogue()->find('en', $code);

            $this->assertInstanceOf(ScenarioContent::class, $content, "`{$code}` is missing.");
            $this->assertSame($level, $content->level, "`{$code}` has the wrong level.");
            $this->assertSame($register, $content->register, "`{$code}` has the wrong register.");
        }
    }

    public function test_an_unknown_code_returns_null(): void
    {
        $catalogue = $this->catalogue();

        $this->assertNull($catalogue->find('en', 'not_a_scenario'));
        $this->assertNull($catalogue->find('en', 'airport_check_in'));
    }

    public function test_a_language_without_a_catalogue_has_no_scenarios(): void
    {
        $catalogue = $this->catalogue();

        $this->assertSame([], $catalogue->forLanguage('xx'));
        $this->assertNull($catalogue->find('xx', 'free_talk'));
    }

    public function test_path_traversal_language_code_returns_nothing(): void
    {
        $catalogue = $this->catalogue();

        $this->assertSame([], $catalogue->forLanguage('../../../etc/passwd'));
        $this->assertSame([], $catalogue->forLanguage('en/../../config'));
        $this->assertNull($catalogue->find('../secret', 'free_talk'));
        $this->assertNull($catalogue->find('../secret', 'airport_checkin'));
    }

    private function catalogue(): ScenarioCatalogue
    {
        return $this->app->make(ScenarioCatalogue::class);
    }
}
