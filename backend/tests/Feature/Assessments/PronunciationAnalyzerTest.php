<?php

namespace Tests\Feature\Assessments;

use App\Assessments\PronunciationAnalyzer;
use App\Assessments\PronunciationAssessment;
use App\Assessments\SpeechFeatures;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The clarity evidence behind the pronunciation section (plan §5).
 *
 * These are unit tests because the figures are arithmetic over a provider
 * payload: the interesting cases are the ones a happy-path fixture never
 * produces — a clip too short to summarise, a word repeated four times, a run of
 * hesitations the recogniser was unsure about. Each case names the wrong answer
 * it prevents.
 */
class PronunciationAnalyzerTest extends TestCase
{
    public function test_it_reports_a_band_and_a_share_for_a_full_sentence(): void
    {
        // Twenty words, six of them below the threshold: 70% came through clearly.
        $features = $this->features(array_merge(
            $this->words(['the', 'quick', 'brown', 'fox', 'jumps', 'over'], confidence: 0.4),
            $this->words(range(1, 14), confidence: 0.95),
        ));

        $pronunciation = $this->analyzer()->analyze($features);

        $this->assertSame(PronunciationAssessment::BAND_UNEVEN, $pronunciation->band);
        $this->assertSame(70, $pronunciation->clarityPercent);
        $this->assertFalse($pronunciation->isThinSample);
        $this->assertSame(0.65, $pronunciation->uncertainThreshold);
        // All six are at the same confidence, so the tie is broken alphabetically:
        // the list must not reorder itself between two runs of the same recording.
        $this->assertSame(
            ['brown', 'fox', 'jumps', 'over', 'quick', 'the'],
            array_column($pronunciation->uncertainWords, 'word'),
        );
        $this->assertSame(0.4, $pronunciation->uncertainWords[0]['confidence']);
    }

    public function test_it_withholds_the_summary_from_a_sample_too_short_to_have_one(): void
    {
        // Three words, one of them unclear: 67%, which is arithmetic about the
        // sample size rather than a measurement of the learner. The word is still
        // listed, because that part of the evidence is real.
        $pronunciation = $this->analyzer()->analyze($this->features([
            $this->word('thought', confidence: 0.3),
            $this->word('about', confidence: 0.95),
            $this->word('it', confidence: 0.95),
        ]));

        $this->assertTrue($pronunciation->isThinSample);
        $this->assertNull($pronunciation->band);
        $this->assertNull($pronunciation->clarityPercent);
        $this->assertSame(['thought'], array_column($pronunciation->uncertainWords, 'word'));
    }

    public function test_it_reports_nothing_measured_when_the_provider_scored_no_words(): void
    {
        // A model that returns no confidences leaves nothing to measure, and an
        // "effortful" band for a learner who was simply not scored would be a
        // verdict invented from an absent measurement.
        $pronunciation = $this->analyzer()->analyze(new SpeechFeatures(wordCount: 12));

        $this->assertTrue($pronunciation->isThinSample);
        $this->assertNull($pronunciation->band);
        $this->assertNull($pronunciation->clarityPercent);
        $this->assertSame([], $pronunciation->uncertainWords);
        $this->assertSame([], $this->analyzer()->toPromptArray($pronunciation));
    }

    public function test_it_lists_a_repeated_word_once_at_its_worst_confidence(): void
    {
        // "thought" said four times, unclear once. The learner is being pointed at
        // a word, not at four instants in the recording, and the occurrence worth
        // acting on is the worst one.
        $pronunciation = $this->analyzer()->analyze($this->features([
            $this->word('thought', confidence: 0.95),
            $this->word('about', confidence: 0.95),
            $this->word('thought', confidence: 0.9),
            $this->word('thought', confidence: 0.2),
            $this->word('thought', confidence: 0.9),
        ]));

        $this->assertSame([['word' => 'thought', 'confidence' => 0.2]], $pronunciation->uncertainWords);
    }

    public function test_it_keeps_hesitations_out_of_a_list_about_pronunciation(): void
    {
        // "um" scored badly is evidence about hesitation, which the fluency
        // report already names. Listing it as a word to practise would file it
        // under the wrong heading — and it would also drag the clarity figure
        // down, which is why the hesitations are dropped before the share is
        // computed rather than only before the list is built.
        $pronunciation = $this->analyzer()->analyze($this->features(array_merge(
            [$this->word('um', confidence: 0.2), $this->word('hmm', confidence: 0.3)],
            $this->words(range(1, 23), confidence: 0.95),
            [$this->word('thought', confidence: 0.4)],
        )));

        $this->assertSame(['thought'], array_column($pronunciation->uncertainWords, 'word'));
        $this->assertFalse($pronunciation->isThinSample);
        $this->assertSame(96, $pronunciation->clarityPercent);
    }

    public function test_it_takes_the_worst_words_and_never_more_than_eight(): void
    {
        $words = [];
        for ($index = 0; $index < 12; $index++) {
            // Increasing confidence, so the first word is the worst.
            $words[] = $this->word('word'.$index, confidence: 0.1 + $index / 100);
        }

        $pronunciation = $this->analyzer()->analyze($this->features($words));

        $this->assertCount(8, $pronunciation->uncertainWords);
        $this->assertSame('word0', $pronunciation->uncertainWords[0]['word']);
        $this->assertSame('word7', $pronunciation->uncertainWords[7]['word']);
    }

    public function test_a_word_at_the_threshold_is_not_listed(): void
    {
        // The threshold is a floor for attention, and a word exactly at it was
        // scored as reliable. Listing it would make the list unstable across runs.
        $pronunciation = $this->analyzer()->analyze($this->features(array_merge(
            [$this->word('borderline', confidence: 0.65)],
            $this->words(range(1, 19), confidence: 0.99),
        )));

        $this->assertSame([], $pronunciation->uncertainWords);
        $this->assertSame(100, $pronunciation->clarityPercent);
    }

    /**
     * @param  list<array{confidence: float}>  $words
     */
    #[DataProvider('bandCases')]
    public function test_it_places_a_share_in_the_band_the_interface_describes(array $words, string $expected): void
    {
        $this->assertSame($expected, $this->analyzer()->analyze($this->features($words))->band);
    }

    /**
     * @return array<string, array{list<array{confidence: float}>, string}>
     */
    public static function bandCases(): array
    {
        // A twelve-word sample is thin, so each case is built from twenty words to
        // exercise the bands at the point where they are actually reported.
        return [
            'everything clear' => [self::twenty(0), PronunciationAssessment::BAND_CLEAR],
            'the edge of clear' => [self::twenty(2), PronunciationAssessment::BAND_CLEAR],
            'just below clear' => [self::twenty(3), PronunciationAssessment::BAND_MOSTLY_CLEAR],
            'the edge of mostly clear' => [self::twenty(4), PronunciationAssessment::BAND_MOSTLY_CLEAR],
            'just below mostly clear' => [self::twenty(5), PronunciationAssessment::BAND_UNEVEN],
            'the edge of uneven' => [self::twenty(7), PronunciationAssessment::BAND_UNEVEN],
            'just below uneven' => [self::twenty(8), PronunciationAssessment::BAND_EFFORTFUL],
            'nothing clear at all' => [self::twenty(20), PronunciationAssessment::BAND_EFFORTFUL],
        ];
    }

    /**
     * The block the analysis is given: the words, and whether the clip was too
     * short to say anything about clarity as a whole — never the percentage,
     * which would invite the model to repeat it as a grade.
     */
    public function test_the_prompt_block_carries_the_words_and_withholds_the_percentage(): void
    {
        $analyzer = $this->analyzer();
        $pronunciation = $analyzer->analyze($this->features([
            $this->word('thought', confidence: 0.2),
            $this->word('thorough', confidence: 0.5),
            $this->word('about', confidence: 0.95),
            $this->word('it', confidence: 0.95),
        ]));

        $this->assertSame([
            'words_the_recogniser_struggled_with' => ['thought', 'thorough'],
            'sample_too_short_to_judge_overall' => true,
        ], $analyzer->toPromptArray($pronunciation));
    }

    public function test_the_prompt_block_says_nothing_when_every_word_came_through(): void
    {
        $analyzer = $this->analyzer();
        $pronunciation = $analyzer->analyze($this->features($this->words(range(1, 24), confidence: 0.99)));

        $this->assertSame(PronunciationAssessment::BAND_CLEAR, $pronunciation->band);
        $this->assertSame([], $analyzer->toPromptArray($pronunciation));
    }

    /**
     * The shape the interface reads, with the sentence the analysis wrote on it.
     *
     * Every key is present, including the ones carrying null: the client reads a
     * null band as "say nothing about the overall clarity", and `is_thin_sample`
     * stays a real boolean so the screen never has to infer the rule from which
     * keys happen to exist.
     */
    public function test_the_stored_shape_carries_every_key_the_interface_reads(): void
    {
        $pronunciation = $this->analyzer()->analyze($this->features([
            $this->word('thought', confidence: 0.2),
            $this->word('about', confidence: 0.95),
        ]))->withAdvice('「thought」という単語をもう一度ゆっくり言ってみましょう。');

        $this->assertSame([
            'band' => null,
            'clarity_percent' => null,
            'uncertain_threshold' => 0.65,
            'uncertain_words' => [['word' => 'thought', 'confidence' => 0.2]],
            'is_thin_sample' => true,
            'advice' => '「thought」という単語をもう一度ゆっくり言ってみましょう。',
        ], $pronunciation->toArray());
    }

    /**
     * @param  list<string>  $words
     * @return list<array{word: string, confidence: float}>
     */
    private function words(array $words, float $confidence = 0.99): array
    {
        return array_map(fn (string|int $word): array => $this->word((string) $word, $confidence), $words);
    }

    /**
     * @return array{word: string, confidence: float}
     */
    private function word(string $word, float $confidence): array
    {
        return ['word' => $word, 'confidence' => $confidence];
    }

    /**
     * @param  list<array{word: string, confidence: float}>  $words
     */
    private function features(array $words): SpeechFeatures
    {
        return new SpeechFeatures(wordCount: count($words), wordConfidences: $words);
    }

    /**
     * Twenty scored words, $unclear of them below the threshold.
     *
     * @return list<array{word: string, confidence: float}>
     */
    private static function twenty(int $unclear): array
    {
        $words = [];

        for ($index = 0; $index < 20; $index++) {
            $words[] = [
                'word' => 'word'.$index,
                'confidence' => $index < $unclear ? 0.3 : 0.95,
            ];
        }

        return $words;
    }

    private function analyzer(): PronunciationAnalyzer
    {
        return new PronunciationAnalyzer;
    }
}
