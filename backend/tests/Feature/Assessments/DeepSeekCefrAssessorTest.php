<?php

namespace Tests\Feature\Assessments;

use App\Assessments\AssessmentAnalysisFailed;
use App\Assessments\DeepSeekCefrAssessor;
use App\Assessments\SpeechFeatures;
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

    public function test_it_hands_the_examiner_the_fluency_evidence_alongside_the_transcript(): void
    {
        StructuredAnonymousAgent::fake([[
            'cefr_level' => 'A2',
            'summary' => 'You are at A2.',
            'strengths' => ['Simple sentences'],
            'weaknesses' => ['Past tense'],
        ]]);

        $this->assessor()->assess(
            $this->transcription('I go to school yesterday.', features: new SpeechFeatures(
                wordCount: 40,
                wordsPerMinute: 62.5,
                speakingSeconds: 38.4,
                silenceShare: 0.3,
                longestSilenceSeconds: 6.1,
                longestPauseSeconds: 4.2,
                lowConfidenceShare: 0.15,
                fillers: ['um', 'uh'],
                uncertainWords: ['yesterday'],
            )),
            $this->user(),
        );

        // Judging a spoken sample on its transcript alone turns it into a written
        // one: the pauses and the hesitations have to reach the prompt, and the
        // confidence figure has to arrive hedged, because it measures the audio
        // as much as the learner.
        StructuredAnonymousAgent::assertPrompted(static function ($prompt): bool {
            $text = (string) $prompt->prompt;

            foreach ([
                '"words_per_minute": 62.5',
                '"longest_pause_between_words_seconds": 4.2',
                '"longest_silence_seconds": 6.1',
                '"filler_words_used": [',
                '"words_the_recogniser_was_unsure_about": [',
                // The two guardrails that keep delivery and audio quality from
                // deciding a band on their own.
                'must not move the band on their own',
                'Never lower the band because',
                'Transcript:',
                'I go to school yesterday.',
            ] as $needle) {
                if (! str_contains($text, $needle)) {
                    fwrite(STDERR, "MISSING: {$needle}\n");

                    return false;
                }
            }

            return true;
        });
    }

    public function test_it_says_so_when_the_provider_returned_no_timings(): void
    {
        StructuredAnonymousAgent::fake([[
            'cefr_level' => 'A2',
            'summary' => 'You are at A2.',
            'strengths' => ['Simple sentences'],
            'weaknesses' => ['Past tense'],
        ]]);

        $this->assessor()->assess(
            $this->transcription('I go to school yesterday.', features: new SpeechFeatures(
                wordCount: 5,
                lowConfidenceShare: 0.4,
            )),
            $this->user(),
        );

        // Words without timings are not an empty measurement: the confidence
        // figures are real and worth sending, but the prompt must not invite the
        // model to reason about a fluency figure that was never measured.
        StructuredAnonymousAgent::assertPrompted(static function ($prompt): bool {
            $text = (string) $prompt->prompt;

            foreach ([
                'carries no word timings',
                'no fluency figure appears above',
                '"low_confidence_word_share": 0.4',
            ] as $needle) {
                if (! str_contains($text, $needle)) {
                    fwrite(STDERR, "MISSING: {$needle}\n");

                    return false;
                }
            }

            // The measurement block must not carry a fluency key. (The words
            // `words_per_minute` still appear in the explanation below it, which is
            // why this checks the JSON key rather than the bare phrase.)
            return ! str_contains($text, '"words_per_minute"');
        });
    }

    public function test_it_sends_the_bare_transcript_when_the_provider_measured_nothing(): void
    {
        StructuredAnonymousAgent::fake([[
            'cefr_level' => 'A1',
            'summary' => 'Just starting.',
            'strengths' => ['Greetings'],
            'weaknesses' => ['Articles'],
        ]]);

        $this->assessor()->assess($this->transcription('Hello.'), $this->user());

        // No word timings means no measurements: the prompt must not invent a
        // block of zeroes the provider never produced.
        StructuredAnonymousAgent::assertPrompted(static fn ($prompt): bool => (string) $prompt->prompt === 'Hello.');
    }

    private function assessor(): DeepSeekCefrAssessor
    {
        return $this->app->make(DeepSeekCefrAssessor::class);
    }

    private function transcription(string $text, ?SpeechFeatures $features = null): Transcription
    {
        return new Transcription(
            text: $text,
            durationSeconds: 30.0,
            raw: [],
            features: $features ?? new SpeechFeatures(wordCount: 0),
        );
    }

    private function user(): User
    {
        return (new User)->forceFill(['id' => 7, 'target_language' => 'en']);
    }
}
