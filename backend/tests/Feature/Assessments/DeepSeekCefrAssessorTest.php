<?php

namespace Tests\Feature\Assessments;

use App\Assessments\AssessmentAnalysisFailed;
use App\Assessments\DeepSeekCefrAssessor;
use App\Assessments\Transcription;
use App\Enums\CefrLevel;
use App\Models\User;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\TestCase;

class DeepSeekCefrAssessorTest extends TestCase
{
    public function test_it_returns_the_level_and_feedback_the_model_produced(): void
    {
        StructuredAnonymousAgent::fake([[
            'cefr_level' => 'B1',
            'summary' => 'You are at B1: everyday topics are comfortable, tenses still slip.',
            'strengths' => ['Clear everyday vocabulary'],
            'weaknesses' => ['Present perfect', 'Articles'],
        ]]);

        $result = $this->assessor()->assess(
            $this->transcription('I have been learning English for six years.'),
            $this->user(),
        );

        $this->assertSame(CefrLevel::B1, $result->level);
        $this->assertSame('You are at B1: everyday topics are comfortable, tenses still slip.', $result->summary);
        $this->assertSame(['Clear everyday vocabulary'], $result->strengths);
        $this->assertSame(['Present perfect', 'Articles'], $result->weaknesses);
        $this->assertSame([
            'cefr_level' => 'B1',
            'summary' => 'You are at B1: everyday topics are comfortable, tenses still slip.',
            'strengths' => ['Clear everyday vocabulary'],
            'weaknesses' => ['Present perfect', 'Articles'],
        ], $result->toArray());

        StructuredAnonymousAgent::assertPrompted('I have been learning English for six years.');
        StructuredAnonymousAgent::assertPromptedTimes(1);
    }

    public function test_it_asks_the_model_named_in_the_configuration(): void
    {
        config(['assessments.analysis.model' => 'deepseek-v4-pro']);
        StructuredAnonymousAgent::fake([[
            'cefr_level' => 'A2',
            'summary' => 'You are at A2.',
            'strengths' => ['Simple sentences'],
            'weaknesses' => ['Past tense'],
        ]]);

        $this->assessor()->assess($this->transcription('I go to school yesterday.'), $this->user());

        StructuredAnonymousAgent::assertPrompted(static fn ($prompt): bool => $prompt->model === 'deepseek-v4-pro');
    }

    public function test_it_fails_when_the_model_answers_outside_the_schema(): void
    {
        StructuredAnonymousAgent::fake([['answer' => 'I cannot do that']]);

        $this->expectException(AssessmentAnalysisFailed::class);
        $this->expectExceptionMessage('unusable judgement');

        $this->assessor()->assess($this->transcription('Hello.'), $this->user());
    }

    public function test_it_fails_when_the_model_picks_a_band_outside_the_scale(): void
    {
        StructuredAnonymousAgent::fake([[
            'cefr_level' => 'C2',
            'summary' => 'Near native.',
            'strengths' => ['Everything'],
            'weaknesses' => ['Nothing'],
        ]]);

        $this->expectException(AssessmentAnalysisFailed::class);

        $this->assessor()->assess($this->transcription('Hello.'), $this->user());
    }

    public function test_it_fails_when_the_response_carries_no_structured_output(): void
    {
        // What the SDK hands back when the model's answer does not decode.
        StructuredAnonymousAgent::fake([[]]);

        $this->expectException(AssessmentAnalysisFailed::class);

        $this->assessor()->assess($this->transcription('Hello.'), $this->user());
    }

    public function test_it_fails_when_the_feedback_lists_are_empty(): void
    {
        StructuredAnonymousAgent::fake([[
            'cefr_level' => 'A1',
            'summary' => 'Just starting.',
            'strengths' => [],
            'weaknesses' => [],
        ]]);

        $this->expectException(AssessmentAnalysisFailed::class);

        $this->assessor()->assess($this->transcription('Hello.'), $this->user());
    }

    private function assessor(): DeepSeekCefrAssessor
    {
        return $this->app->make(DeepSeekCefrAssessor::class);
    }

    private function transcription(string $text): Transcription
    {
        return new Transcription(text: $text, durationSeconds: 30.0, raw: []);
    }

    private function user(): User
    {
        return (new User)->forceFill(['id' => 7, 'target_language' => 'en']);
    }
}
