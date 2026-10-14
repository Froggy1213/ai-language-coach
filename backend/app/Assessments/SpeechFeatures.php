<?php

namespace App\Assessments;

/**
 * The fluency evidence a transcript alone cannot carry (plan §5).
 *
 * Speech-to-text normalises away exactly the signals that separate a B1 speaker
 * from a C1 one: hesitation, restarts, a word the recogniser itself was unsure
 * about. Judging only the final string therefore reduces a spoken assessment to
 * a written one. Deepgram already returns the timing and confidence of every
 * word, so this turns those into a handful of numbers an examiner prompt can use:
 *
 * - speech rate in words per minute, over the time actually spent speaking;
 * - how much of the recording was *not* speech, including the silence before the
 *   first word and after the last;
 * - the longest silence in the recording, which is where a learner was hunting
 *   for a word rather than finishing a thought;
 * - the share of words the recogniser was unsure about. This is the weakest of
 *   the signals and the prompt is told so: recogniser confidence is influenced
 *   by audio quality, accent and vocabulary alike, so it is evidence of unclear
 *   *audio* rather than a pronunciation score, and nothing here should be
 *   presented to a learner as one;
 * - filler words, which the recogniser keeps only because `filler_words` is on.
 *
 * Every measurement is nullable: a response without word timings (a different
 * model, a provider swap, a stubbed test) leaves the timing figures null, and the
 * assessor says less rather than reporting a zero it did not measure. Rate is
 * additionally withheld for samples too short to extrapolate from — a single
 * 0.2 s word is 300 words per minute, which is not a measurement of anybody's
 * speech rate.
 */
final readonly class SpeechFeatures
{
    /**
     * Words Deepgram returns verbatim when `filler_words` is enabled. Kept to
     * the closed set its documentation lists, so an ordinary word is never
     * counted as a hesitation.
     *
     * @see https://developers.deepgram.com/docs/filler-words
     */
    private const FILLERS = ['um', 'uh', 'er', 'ah', 'mm', 'hmm', 'mhm', 'uh-huh', 'huh'];

    /**
     * Below this per-word confidence the recogniser was guessing. The value is
     * Deepgram's own convention: its documentation treats 0.75 as the point
     * where a word is worth a human's attention.
     */
    private const LOW_CONFIDENCE = 0.75;

    /**
     * A rate needs enough speech to be a rate.
     *
     * Five words is the shortest sample whose rate is not dominated by a single
     * syllable, and a second and a half of speech is where a words-per-minute
     * figure stops being an extrapolation from a rounding error — five words in
     * 1.2 s reads as 250 wpm, which is a measurement of the arithmetic rather
     * than of anybody's speech. Below either floor the figure is withheld and the
     * prompt says so.
     */
    private const MIN_WORDS_FOR_RATE = 5;

    private const MIN_SPEAKING_SECONDS_FOR_RATE = 1.5;

    /**
     * @param  int  $wordCount  Recognised words, fillers included.
     * @param  float|null  $wordsPerMinute  Speech rate over the speaking time.
     * @param  float|null  $speakingSeconds  Time actually covered by words.
     * @param  float|null  $silenceShare  Fraction of the recording that held no words.
     * @param  float|null  $longestSilenceSeconds  Longest run of no words anywhere in the recording.
     * @param  float|null  $longestPauseSeconds  Longest gap *between* two words.
     * @param  float|null  $lowConfidenceShare  Share of words below the confidence floor.
     * @param  list<string>  $fillers  Filler words that were actually used.
     * @param  list<string>  $uncertainWords  Up to ten of the lowest-confidence words.
     */
    public function __construct(
        public int $wordCount,
        public ?float $wordsPerMinute = null,
        public ?float $speakingSeconds = null,
        public ?float $silenceShare = null,
        public ?float $longestSilenceSeconds = null,
        public ?float $longestPauseSeconds = null,
        public ?float $lowConfidenceShare = null,
        public array $fillers = [],
        public array $uncertainWords = [],
    ) {}

    /**
     * Build from a Deepgram batch response.
     *
     * @param  array<string, mixed>  $raw  The provider response as returned.
     * @param  float  $durationSeconds  Recording length, from `metadata.duration`.
     */
    public static function fromDeepgramResponse(array $raw, float $durationSeconds): self
    {
        $words = data_get($raw, 'results.channels.0.alternatives.0.words');
        $words = is_array($words) ? array_values(array_filter($words, 'is_array')) : [];

        // Chronological order is assumed below, both for the gaps between words
        // and for the span of the recording. The provider returns them in order,
        // but a reordered response would otherwise report a negative span as
        // "100% silence", which is a worse answer than a sorted one.
        usort($words, static function (array $a, array $b): int {
            return ($a['start'] ?? 0) <=> ($b['start'] ?? 0);
        });

        if ($words === []) {
            return new self(wordCount: 0);
        }

        $fillers = [];
        $confidences = [];
        $lowConfidence = 0;
        $firstStart = null;
        $lastEnd = null;
        $spokenSeconds = 0.0;
        $longestPause = 0.0;
        $previousEnd = null;
        $counted = 0;

        foreach ($words as $word) {
            $text = strtolower(trim((string) ($word['word'] ?? '')));

            if ($text === '') {
                continue;
            }

            $counted++;

            if (in_array($text, self::FILLERS, true)) {
                $fillers[] = $text;
            }

            if (is_numeric($word['confidence'] ?? null)) {
                $confidence = (float) $word['confidence'];
                $confidences[] = ['word' => (string) ($word['punctuated_word'] ?? $text), 'confidence' => $confidence];

                if ($confidence < self::LOW_CONFIDENCE) {
                    $lowConfidence++;
                }
            }

            $start = is_numeric($word['start'] ?? null) ? (float) $word['start'] : null;
            $end = is_numeric($word['end'] ?? null) ? (float) $word['end'] : null;

            if ($start === null || $end === null) {
                continue;
            }

            $firstStart ??= $start;
            $lastEnd = $end;

            // Time actually covered by words, not the span from the first word to
            // the last. The difference is the whole point: a learner who says two
            // sentences thirty seconds apart has spoken for a few seconds, and
            // the span would report the silence as speech.
            $spokenSeconds += max(0.0, $end - $start);

            if ($previousEnd !== null && $start > $previousEnd) {
                $longestPause = max($longestPause, $start - $previousEnd);
            }

            $previousEnd = $end;
        }

        if ($counted === 0) {
            return new self(wordCount: 0);
        }

        if ($firstStart === null || $lastEnd === null) {
            // Words came back without a single timing: the count and the
            // confidences are real, every duration is unknown. Reporting the
            // count with `isEmpty()` false is deliberate — the confidence
            // evidence is still worth handing to the examiner — but the prompt
            // has to know that nothing timed was measured, which `hasTimings()`
            // answers.
            return new self(
                wordCount: $counted,
                lowConfidenceShare: $confidences === [] ? null : round($lowConfidence / count($confidences), 3),
                fillers: array_values(array_unique($fillers)),
                uncertainWords: self::mostUncertain($confidences),
            );
        }

        $trailingSilence = max(0.0, $durationSeconds - $lastEnd);
        $silence = $durationSeconds > 0 ? max(0.0, $durationSeconds - $spokenSeconds) : null;

        return new self(
            wordCount: $counted,
            wordsPerMinute: $spokenSeconds >= self::MIN_SPEAKING_SECONDS_FOR_RATE && $counted >= self::MIN_WORDS_FOR_RATE
                ? round($counted / $spokenSeconds * 60, 1)
                : null,
            speakingSeconds: round($spokenSeconds, 2),
            silenceShare: $silence === null ? null : round(min(1.0, $silence / $durationSeconds), 3),
            // The longest silence anywhere: how long the learner was quiet after
            // they stopped talking. A trailing silence is a learner who was
            // finished, which is different from a mid-sentence stall, so both are
            // reported rather than merged.
            longestSilenceSeconds: round(max($firstStart, $trailingSilence, $longestPause), 2),
            longestPauseSeconds: round($longestPause, 2),
            lowConfidenceShare: $confidences === [] ? null : round($lowConfidence / count($confidences), 3),
            fillers: array_values(array_unique($fillers)),
            uncertainWords: self::mostUncertain($confidences),
        );
    }

    /**
     * The ten words the recogniser was least sure about.
     *
     * Sorted by confidence, not by position: taking the first ten below the
     * threshold would drop the single worst word in a long recording, which is
     * the one worth looking at.
     *
     * @param  list<array{word: string, confidence: float}>  $confidences
     * @return list<string>
     */
    private static function mostUncertain(array $confidences): array
    {
        $low = array_values(array_filter(
            $confidences,
            static fn (array $word): bool => $word['confidence'] < self::LOW_CONFIDENCE,
        ));

        usort($low, static fn (array $a, array $b): int => $a['confidence'] <=> $b['confidence']);

        return array_values(array_unique(array_column(array_slice($low, 0, 10), 'word')));
    }

    /**
     * Whether anything was measured at all.
     */
    public function isEmpty(): bool
    {
        return $this->wordCount === 0;
    }

    /**
     * Whether the provider returned word timings.
     *
     * Separate from `isEmpty()` because the two cases need different prompt text:
     * no words at all means there is nothing to assess, whereas words without
     * timings mean the confidence evidence exists but fluency was never measured
     * — and a prompt that quotes `words_per_minute` in that case is asking the
     * model to reason about a field it was not given.
     */
    public function hasTimings(): bool
    {
        return $this->speakingSeconds !== null;
    }

    /**
     * The block handed to the examiner prompt.
     *
     * Deliberately worded as observations rather than scores: the model is asked
     * to weigh them, not to read a grade out of them, and each line says what it
     * does *not* prove so a low-confidence run is not reported as a bad accent.
     *
     * @return array<string, mixed>
     */
    public function toPromptArray(): array
    {
        return array_filter([
            'words_recognised' => $this->wordCount,
            'words_per_minute' => $this->wordsPerMinute,
            'speaking_seconds' => $this->speakingSeconds,
            'silence_share_of_recording' => $this->silenceShare,
            'longest_silence_seconds' => $this->longestSilenceSeconds,
            'longest_pause_between_words_seconds' => $this->longestPauseSeconds,
            'low_confidence_word_share' => $this->lowConfidenceShare,
            'filler_words_used' => $this->fillers,
            'words_the_recogniser_was_unsure_about' => $this->uncertainWords,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
