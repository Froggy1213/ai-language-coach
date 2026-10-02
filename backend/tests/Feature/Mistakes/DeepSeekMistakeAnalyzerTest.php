<?php

namespace Tests\Feature\Mistakes;

use App\Mistakes\DeepSeekMistakeAnalyzer;
use App\Mistakes\MistakeAnalysisFailed;
use App\Models\GrammarPoint;
use App\Models\User;
use Database\Seeders\GrammarPointSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\TestCase;

class DeepSeekMistakeAnalyzerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GrammarPointSeeder::class);
    }

    public function test_it_returns_analyzed_mistakes_mapped_to_the_catalogue(): void
    {
        $user = $this->user();
        $presentSimple = GrammarPoint::query()
            ->where('language', 'en')
            ->where('code', 'present_simple')
            ->firstOrFail();

        StructuredAnonymousAgent::fake([[
            'mistakes' => [
                [
                    'grammar_point_code' => 'present_simple',
                    'user_utterance' => 'He go to work by bus.',
                    'correction' => 'He goes to work by bus.',
                    'explanation' => 'Third-person singular present simple takes -s.',
                ],
            ],
        ]]);

        $result = $this->analyzer()->analyze('He go to work by bus.', $user);

        $this->assertCount(1, $result->mistakes);
        $mistake = $result->mistakes[0];
        $this->assertSame($presentSimple->id, $mistake->grammarPoint->id);
        $this->assertSame('present_simple', $mistake->grammarPoint->code);
        $this->assertSame('He go to work by bus.', $mistake->userUtterance);
        $this->assertSame('He goes to work by bus.', $mistake->correction);
        $this->assertSame('Third-person singular present simple takes -s.', $mistake->explanation);

        StructuredAnonymousAgent::assertPrompted('He go to work by bus.');
        StructuredAnonymousAgent::assertPromptedTimes(1);
    }

    public function test_it_asks_the_model_named_in_the_configuration(): void
    {
        config(['assessments.mistake_analysis.model' => 'deepseek-custom-v3']);

        StructuredAnonymousAgent::fake([[
            'mistakes' => [],
        ]]);

        $this->analyzer()->analyze('Everything is fine.', $this->user());

        StructuredAnonymousAgent::assertPrompted(static fn ($prompt): bool => $prompt->model === 'deepseek-custom-v3');
    }

    public function test_an_unknown_code_becomes_the_uncategorized_sentinel_rather_than_a_crash_or_dropped_row(): void
    {
        $user = $this->user();
        $sentinel = GrammarPoint::query()
            ->where('language', 'en')
            ->where('code', GrammarPoint::UNCATEGORIZED_CODE)
            ->firstOrFail();

        StructuredAnonymousAgent::fake([[
            'mistakes' => [
                [
                    'grammar_point_code' => 'invented_non_existent_code_123',
                    'user_utterance' => 'I would of gone.',
                    'correction' => 'I would have gone.',
                    'explanation' => 'Use "have" instead of "of" after modal verbs.',
                ],
            ],
        ]]);

        $result = $this->analyzer()->analyze('I would of gone.', $user);

        $this->assertCount(1, $result->mistakes);
        $mistake = $result->mistakes[0];
        $this->assertSame($sentinel->id, $mistake->grammarPoint->id);
        $this->assertSame(GrammarPoint::UNCATEGORIZED_CODE, $mistake->grammarPoint->code);
        $this->assertSame('I would of gone.', $mistake->userUtterance);
    }

    public function test_an_explicit_uncategorized_code_maps_to_the_sentinel(): void
    {
        $user = $this->user();
        $sentinel = GrammarPoint::query()
            ->where('language', 'en')
            ->where('code', GrammarPoint::UNCATEGORIZED_CODE)
            ->firstOrFail();

        StructuredAnonymousAgent::fake([[
            'mistakes' => [
                [
                    'grammar_point_code' => 'uncategorized',
                    'user_utterance' => 'Some idiom slip.',
                    'correction' => 'Correct idiom.',
                    'explanation' => 'Uncategorized idiom slip.',
                ],
            ],
        ]]);

        $result = $this->analyzer()->analyze('Some idiom slip.', $user);

        $this->assertCount(1, $result->mistakes);
        $this->assertSame($sentinel->id, $result->mistakes[0]->grammarPoint->id);
    }

    public function test_it_returns_an_empty_list_when_no_mistakes_occurred(): void
    {
        StructuredAnonymousAgent::fake([[
            'mistakes' => [],
        ]]);

        $result = $this->analyzer()->analyze('I went to school yesterday and had a great time.', $this->user());

        $this->assertCount(0, $result->mistakes);
    }

    public function test_it_fails_when_the_response_carries_no_structured_output(): void
    {
        // What the SDK hands back when the model output does not decode.
        StructuredAnonymousAgent::fake([[]]);

        $this->expectException(MistakeAnalysisFailed::class);

        $this->analyzer()->analyze('Hello.', $this->user());
    }

    public function test_it_fails_when_the_model_answers_outside_the_schema(): void
    {
        StructuredAnonymousAgent::fake([['answer' => 'I cannot process this']]);

        $this->expectException(MistakeAnalysisFailed::class);

        $this->analyzer()->analyze('Hello.', $this->user());
    }

    public function test_it_fails_when_a_required_field_is_missing(): void
    {
        StructuredAnonymousAgent::fake([[
            'mistakes' => [
                [
                    'grammar_point_code' => 'present_simple',
                    'user_utterance' => 'He go',
                    'correction' => 'He goes',
                    // missing explanation
                ],
            ],
        ]]);

        $this->expectException(MistakeAnalysisFailed::class);

        $this->analyzer()->analyze('He go', $this->user());
    }

    public function test_it_fails_when_a_required_field_is_empty(): void
    {
        StructuredAnonymousAgent::fake([[
            'mistakes' => [
                [
                    'grammar_point_code' => 'present_simple',
                    'user_utterance' => '   ',
                    'correction' => 'He goes',
                    'explanation' => 'Explanation here',
                ],
            ],
        ]]);

        $this->expectException(MistakeAnalysisFailed::class);

        $this->analyzer()->analyze('He go', $this->user());
    }

    private function analyzer(): DeepSeekMistakeAnalyzer
    {
        return $this->app->make(DeepSeekMistakeAnalyzer::class);
    }

    private function user(): User
    {
        return User::factory()->create(['target_language' => 'en']);
    }
}
