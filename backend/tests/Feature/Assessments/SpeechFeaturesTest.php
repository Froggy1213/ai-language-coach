<?php

namespace Tests\Feature\Assessments;

use App\Assessments\SpeechFeatures;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The numbers the examiner prompt is given (plan §5).
 *
 * These are unit tests rather than HTTP-level ones on purpose: the figures are
 * arithmetic over a provider payload, and the interesting cases are the shapes a
 * real response takes but a happy-path fixture never produces — words without
 * timings, a clip too short to extrapolate a rate from, silence at the edges.
 * Each case names the wrong answer it prevents.
 */
class SpeechFeaturesTest extends TestCase
{
    public function test_it_measures_the_time_actually_spent_speaking(): void
    {
        // Two seconds of speech spread across ten seconds of recording. The span
        // from the first word to the last would call ten seconds of it speech and
        // report no silence at all.
        $features = $this->features(
            words: [
                $this->word('hello', 0.0, 1.0),
                $this->word('world', 9.0, 10.0),
            ],
            duration: 10.0,
        );

        $this->assertSame(2.0, $features->speakingSeconds);
        $this->assertSame(0.8, $features->silenceShare);
    }

    public function test_it_separates_a_stall_between_words_from_silence_at_the_edges(): void
    {
        $features = $this->features(
            words: [
                $this->word('hello', 2.0, 2.5),
                $this->word('there', 6.5, 7.0),
            ],
            duration: 20.0,
        );

        // 3.5 s of nothing between two words is a learner searching for one.
        $this->assertSame(4.0, $features->longestPauseSeconds);
        // 13 s of nothing after the last word is a learner who had finished —
        // a different observation, and the reason both are reported.
        $this->assertSame(13.0, $features->longestSilenceSeconds);
    }

    public function test_it_withholds_a_speech_rate_from_a_sample_too_short_to_have_one(): void
    {
        // One word lasting 0.2 s extrapolates to 300 words per minute. Nobody
        // speaks at 300 words per minute; the number is an artefact of dividing
        // by a rounding error, and the prompt has been told a rate "supports the
        // band", so a fake one is worse than none.
        $features = $this->features(
            words: [$this->word('yes', 0.0, 0.2)],
            duration: 5.0,
        );

        $this->assertNull($features->wordsPerMinute);
        $this->assertSame(0.2, $features->speakingSeconds);
        $this->assertSame(1, $features->wordCount);
    }

    public function test_it_reports_a_rate_once_there_is_enough_speech_for_one(): void
    {
        $words = [];
        for ($index = 0; $index < 10; $index++) {
            $start = $index * 1.2;
            $words[] = $this->word('word'.$index, $start, $start + 1.2);
        }

        $features = $this->features(words: $words, duration: 12.0);

        // Ten words in twelve seconds of speech.
        $this->assertSame(50.0, $features->wordsPerMinute);
    }

    public function test_it_reports_no_timings_when_the_provider_returned_none(): void
    {
        // A model or provider that returns words without `start`/`end` still
        // returns confidences. Everything timed is unknown, and the prompt is
        // told so instead of being handed zeroes.
        $features = $this->features(words: [
            ['word' => 'hello', 'confidence' => 0.4],
            ['word' => 'there', 'confidence' => 0.9],
        ], duration: 10.0);

        $this->assertSame(2, $features->wordCount);
        $this->assertFalse($features->isEmpty());
        $this->assertFalse($features->hasTimings());
        $this->assertNull($features->speakingSeconds);
        $this->assertNull($features->silenceShare);
        $this->assertNull($features->longestSilenceSeconds);
        $this->assertSame(['hello'], $features->uncertainWords);
        $this->assertSame(0.5, $features->lowConfidenceShare);
    }

    public function test_it_reads_no_words_as_nothing_measured(): void
    {
        $features = $this->features(words: [], duration: 10.0);

        $this->assertTrue($features->isEmpty());
        $this->assertFalse($features->hasTimings());
        $this->assertSame(['words_recognised' => 0], $features->toPromptArray());
    }

    public function test_it_takes_the_least_confident_words_not_the_earliest(): void
    {
        // Eleven words below the floor, the worst one last. Taking the first ten
        // would drop the single word most worth looking at.
        $words = [];
        for ($index = 0; $index < 10; $index++) {
            $words[] = $this->word('word'.$index, $index * 0.5, $index * 0.5 + 0.4, 0.74);
        }
        $words[] = $this->word('worst', 6.0, 6.4, 0.10);

        $features = $this->features(words: $words, duration: 7.0);

        $this->assertCount(10, $features->uncertainWords);
        $this->assertContains('worst', $features->uncertainWords);
    }

    public function test_it_counts_fillers_and_ignores_ordinary_words(): void
    {
        $features = $this->features(words: [
            $this->word('um', 0.0, 0.2),
            $this->word('um', 0.3, 0.5),
            $this->word('hmm', 0.6, 0.8),
            $this->word('summary', 0.9, 1.3),
        ], duration: 2.0);

        $this->assertSame(['um', 'hmm'], $features->fillers);
    }

    public function test_it_survives_words_arriving_out_of_order(): void
    {
        // The provider returns them in order; a reordered payload would otherwise
        // compute a negative span and report the recording as entirely silent.
        $features = $this->features(words: [
            $this->word('second', 5.0, 6.0),
            $this->word('first', 1.0, 2.0),
        ], duration: 10.0);

        $this->assertSame(2.0, $features->speakingSeconds);
        $this->assertSame(0.8, $features->silenceShare);
        $this->assertSame(3.0, $features->longestPauseSeconds);
    }

    /**
     * @param  list<array<string, mixed>>  $words
     */
    #[DataProvider('promptArrayCases')]
    public function test_the_prompt_array_carries_only_what_was_measured(array $words, float $duration, array $expected): void
    {
        $this->assertSame($expected, $this->features($words, $duration)->toPromptArray());
    }

    /**
     * @return array<string, array{list<array<string, mixed>>, float, array<string, mixed>}>
     */
    public static function promptArrayCases(): array
    {
        return [
            'no words at all' => [[], 10.0, ['words_recognised' => 0]],
            'timings but no confidences' => [
                [['word' => 'a', 'start' => 0.0, 'end' => 1.0]],
                10.0,
                [
                    'words_recognised' => 1,
                    'speaking_seconds' => 1.0,
                    'silence_share_of_recording' => 0.9,
                    'longest_silence_seconds' => 9.0,
                    'longest_pause_between_words_seconds' => 0.0,
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $words
     */
    private function features(array $words, float $duration): SpeechFeatures
    {
        return SpeechFeatures::fromDeepgramResponse([
            'metadata' => ['duration' => $duration],
            'results' => ['channels' => [['alternatives' => [[
                'transcript' => implode(' ', array_column($words, 'word')),
                'words' => $words,
            ]]]]],
        ], $duration);
    }

    /**
     * @return array<string, mixed>
     */
    private function word(string $word, float $start, float $end, float $confidence = 0.99): array
    {
        return [
            'word' => $word,
            'punctuated_word' => $word,
            'start' => $start,
            'end' => $end,
            'confidence' => $confidence,
        ];
    }
}
