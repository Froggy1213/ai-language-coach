<?php

namespace Tests\Feature\Mistakes;

use App\Enums\VoiceSessionStatus;
use App\Mistakes\DeepSeekGoalReviewer;
use App\Mistakes\GoalReviewFailed;
use App\Models\User;
use App\Models\VoiceSession;
use App\Scenarios\ScenarioCatalogue;
use App\Scenarios\ScenarioContent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\TestCase;

class DeepSeekGoalReviewerTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @var list<array{turn_id: string, transcript: string}>
     */
    private const TRANSCRIPT = [
        ['turn_id' => 't1', 'transcript' => 'Good evening. Here is my passport.'],
        ['turn_id' => 't2', 'transcript' => 'I have a connecting flight to Seoul.'],
        ['turn_id' => 't3', 'transcript' => 'Could I have a window seat?'],
    ];

    public function test_it_returns_the_goal_verdict_and_the_missed_expressions(): void
    {
        StructuredAnonymousAgent::fake([[
            'goal_achieved' => true,
            'missed_expressions' => [
                'Will my bag go all the way through?',
                'Could I have a window seat?',
            ],
        ]]);

        $result = $this->reviewer()->review($this->scenarioSession());

        $this->assertTrue($result->goalAchieved);
        $this->assertSame(
            ['Will my bag go all the way through?', 'Could I have a window seat?'],
            $result->missedExpressions,
        );

        // The learner's utterances are the whole user message, one per line.
        StructuredAnonymousAgent::assertPrompted(implode("\n", array_column(self::TRANSCRIPT, 'transcript')));
        StructuredAnonymousAgent::assertPromptedTimes(1);
    }

    public function test_the_instructions_carry_the_scenario_goal_and_success_criteria_but_do_not_ask_for_grammar(): void
    {
        $scenario = $this->scenario('airport_checkin');

        StructuredAnonymousAgent::fake([[
            'goal_achieved' => true,
            'missed_expressions' => [],
        ]]);

        $this->reviewer()->review($this->scenarioSession());

        StructuredAnonymousAgent::assertPrompted(function ($prompt) use ($scenario): bool {
            $instructions = (string) $prompt->agent->instructions();

            $this->assertStringContainsString($scenario->learnerGoal, $instructions);
            $this->assertStringContainsString($scenario->successCriteria, $instructions);
            $this->assertStringContainsString($scenario->usefulPhrases[0], $instructions);

            // This prompt judges communication. It must not become a second
            // mistake analysis, so it neither asks for a grammar verdict nor
            // carries the grammar catalogue's fields.
            $this->assertMatchesRegularExpression('/not a grammar checker/i', $instructions);
            $this->assertStringNotContainsString('grammar mistakes', strtolower($instructions));
            $this->assertStringNotContainsString('grammatical mistakes', strtolower($instructions));
            $this->assertDoesNotMatchRegularExpression('/(list|identify) (the )?(\w+ )?grammar(?:tical)? mistakes?/i', $instructions);

            foreach (['user_utterance', 'grammar_point_code'] as $grammarField) {
                $this->assertStringNotContainsString($grammarField, $instructions);
            }

            return true;
        });
    }

    public function test_an_unknown_scenario_code_fails_the_review(): void
    {
        StructuredAnonymousAgent::fake([[
            'goal_achieved' => true,
            'missed_expressions' => [],
        ]]);

        $this->expectException(GoalReviewFailed::class);

        $this->reviewer()->review($this->scenarioSession('not_a_real_scenario'));
    }

    public function test_a_lesson_session_fails_the_review(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => self::TRANSCRIPT,
        ]);

        $this->expectException(GoalReviewFailed::class);

        $this->reviewer()->review($session);
    }

    public function test_a_session_without_learner_utterances_fails_the_review(): void
    {
        StructuredAnonymousAgent::fake([[
            'goal_achieved' => true,
            'missed_expressions' => [],
        ]]);

        $session = $this->scenarioSession(transcript: [
            ['turn_id' => 't1', 'transcript' => '   '],
            ['type' => 'analysis', 'analyzed_at' => '2026-11-03T12:00:00+00:00', 'mistakes_count' => 0],
        ]);

        $this->expectException(GoalReviewFailed::class);

        $this->reviewer()->review($session);
    }

    public function test_it_fails_when_the_response_carries_no_structured_output(): void
    {
        // What the SDK hands back when the model output does not decode.
        StructuredAnonymousAgent::fake([[]]);

        $this->expectException(GoalReviewFailed::class);

        $this->reviewer()->review($this->scenarioSession());
    }

    public function test_it_fails_when_goal_achieved_is_missing(): void
    {
        StructuredAnonymousAgent::fake([[
            'missed_expressions' => [],
        ]]);

        $this->expectException(GoalReviewFailed::class);

        $this->reviewer()->review($this->scenarioSession());
    }

    public function test_it_fails_when_goal_achieved_is_not_a_boolean(): void
    {
        StructuredAnonymousAgent::fake([[
            'goal_achieved' => 'yes',
            'missed_expressions' => [],
        ]]);

        $this->expectException(GoalReviewFailed::class);

        $this->reviewer()->review($this->scenarioSession());
    }

    public function test_it_fails_when_missed_expressions_is_not_an_array(): void
    {
        StructuredAnonymousAgent::fake([[
            'goal_achieved' => true,
            'missed_expressions' => 'Will my bag go all the way through?',
        ]]);

        $this->expectException(GoalReviewFailed::class);

        $this->reviewer()->review($this->scenarioSession());
    }

    public function test_it_asks_the_model_named_in_the_configuration(): void
    {
        config(['assessments.goal_review.model' => 'deepseek-custom-reviewer']);

        StructuredAnonymousAgent::fake([[
            'goal_achieved' => true,
            'missed_expressions' => [],
        ]]);

        $this->reviewer()->review($this->scenarioSession());

        StructuredAnonymousAgent::assertPrompted(
            static fn ($prompt): bool => $prompt->model === 'deepseek-custom-reviewer',
        );
    }

    public function test_it_trims_filters_empty_expressions_and_caps_the_list_at_five(): void
    {
        StructuredAnonymousAgent::fake([[
            'goal_achieved' => false,
            'missed_expressions' => [
                '  Could I have a window seat?  ',
                '',
                '   ',
                'Will my bag go all the way through?',
                'I have a connecting flight to Seoul.',
                'Thanks, that helps.',
                'Goodbye.',
            ],
        ]]);

        $result = $this->reviewer()->review($this->scenarioSession());

        $this->assertFalse($result->goalAchieved);
        $this->assertSame([
            'Could I have a window seat?',
            'Will my bag go all the way through?',
            'I have a connecting flight to Seoul.',
            'Thanks, that helps.',
            'Goodbye.',
        ], $result->missedExpressions);
    }

    private function reviewer(): DeepSeekGoalReviewer
    {
        return $this->app->make(DeepSeekGoalReviewer::class);
    }

    private function scenario(string $code): ScenarioContent
    {
        $scenario = $this->app->make(ScenarioCatalogue::class)->find('en', $code);

        $this->assertInstanceOf(ScenarioContent::class, $scenario);

        return $scenario;
    }

    /**
     * @param  list<array<string, mixed>>  $transcript
     */
    private function scenarioSession(
        string $code = 'airport_checkin',
        array $transcript = self::TRANSCRIPT,
    ): VoiceSession {
        $user = User::factory()->create(['target_language' => 'en']);

        return VoiceSession::factory()->for($user)->scenario($code)->create([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => $transcript,
        ]);
    }
}
