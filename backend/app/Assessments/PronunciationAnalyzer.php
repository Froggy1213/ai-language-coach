<?php

namespace App\Assessments;

/**
 * Turns the recogniser's per-word confidence into the pronunciation evidence the
 * learner is shown, and into the block the analysis is asked to put into words.
 *
 * It is deliberately arithmetic only: no language model runs here, so the
 * measurement of a recording does not change when a prompt, a model or a
 * provider is revised. The model's only job is the wording (`advice`), which is
 * what the published evidence supports — see `PronunciationAssessment`.
 *
 * Two floors, because a wrong one is worse than none:
 *
 * - **Reporting.** Below `MIN_SCORED_WORDS` scored words no band and no
 *   percentage are produced at all. One unclear word in a five-word sample is
 *   20%, a figure nobody could act on, and it is withheld rather than dressed up.
 * - **Attention.** A word is listed for the learner at `UNCERTAIN_THRESHOLD`
 *   (0.65) rather than the 0.75 the examiner prompt uses. The prompt wants the
 *   words a human might check; a learner-facing list has to be short enough to
 *   act on, and Deepgram's own documented guidance is that 0.65 is the point
 *   with high precision for "the recogniser got this wrong".
 *
 * @see SpeechFeatures, which measures the fluency side of the same payload at its own floor.
 */
final class PronunciationAnalyzer
{
    /**
     * At or above this share of confident words the recording came through clean.
     */
    private const CLEAR_SHARE = 0.9;

    private const MOSTLY_CLEAR_SHARE = 0.8;

    private const UNEVEN_SHARE = 0.65;

    /**
     * Below this per-word confidence the word is worth putting in front of the
     * learner. Deepgram's calibrated confidence is documented with ~0.65 as a
     * high-precision threshold for "probably got this word wrong", which is the
     * figure this repository's own vendor survey records.
     *
     * @see benchmarks/research/voice-stack-research-2026-10-14.md, which also cites the source.
     */
    private const UNCERTAIN_THRESHOLD = 0.65;

    /**
     * Too few scored words to report a share from.
     */
    private const MIN_SCORED_WORDS = 20;

    /**
     * How many of the least confident words the learner is shown.
     *
     * A list longer than a handful stops being something to try and becomes a
     * scoreboard, which is exactly the framing this feature is not allowed to use.
     */
    private const MAX_LISTED_WORDS = 8;

    /**
     * Filler words the recogniser keeps verbatim. Showing "um" as a word to
     * practise would be advice about hesitation filed under pronunciation, and
     * the report already names hesitation separately.
     */
    private const NOT_PRONUNCIATION = ['um', 'uh', 'er', 'ah', 'mm', 'hmm', 'mhm', 'uh-huh', 'huh'];

    public function analyze(SpeechFeatures $features): PronunciationAssessment
    {
        $scored = $this->scoredWords($features);

        if ($scored === []) {
            return $this->nothingMeasured();
        }

        $confident = array_filter(
            $scored,
            static fn (array $word): bool => $word['confidence'] >= self::UNCERTAIN_THRESHOLD,
        );

        $share = count($confident) / count($scored);

        // A thin sample is measured and then deliberately not summarised: one
        // unclear word in a five-word clip is 20%, which is arithmetic about the
        // sample size rather than a measurement of the learner. The words below
        // the threshold are still listed.
        $thin = count($scored) < self::MIN_SCORED_WORDS;

        return new PronunciationAssessment(
            band: $thin ? null : self::band($share),
            clarityPercent: $thin ? null : (int) round($share * 100),
            uncertainWords: $this->uncertainWords($scored),
            isThinSample: $thin,
            uncertainThreshold: self::UNCERTAIN_THRESHOLD,
        );
    }

    /**
     * The block the analysis is given, so the sentence it writes is about the
     * words that were actually unclear rather than about pronunciation in
     * general.
     *
     * The percentage is deliberately absent: it is the interface's business, and
     * a prompt that carries a number invites the model to repeat it as a grade.
     *
     * @return array<string, mixed>
     */
    public function toPromptArray(PronunciationAssessment $pronunciation): array
    {
        if ($pronunciation->uncertainWords === []) {
            return [];
        }

        return array_filter([
            'words_the_recogniser_struggled_with' => array_column($pronunciation->uncertainWords, 'word'),
            'sample_too_short_to_judge_overall' => $pronunciation->isThinSample ?: null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function nothingMeasured(): PronunciationAssessment
    {
        return new PronunciationAssessment(
            band: null,
            clarityPercent: null,
            uncertainWords: [],
            isThinSample: true,
            uncertainThreshold: self::UNCERTAIN_THRESHOLD,
        );
    }

    private static function band(float $share): string
    {
        return match (true) {
            $share >= self::CLEAR_SHARE => PronunciationAssessment::BAND_CLEAR,
            $share >= self::MOSTLY_CLEAR_SHARE => PronunciationAssessment::BAND_MOSTLY_CLEAR,
            $share >= self::UNEVEN_SHARE => PronunciationAssessment::BAND_UNEVEN,
            default => PronunciationAssessment::BAND_EFFORTFUL,
        };
    }

    /**
     * Every scoreable word, keyed by its lowercased form.
     *
     * Keying collapses a word said four times into one entry: the learner is being
     * pointed at a word, not at four instants in the recording. The lowest
     * confidence wins the slot, because that is the occurrence worth acting on —
     * which is also why the insertion order of this map is not the spoken order.
     *
     * @return array<string, array{word: string, confidence: float}>
     */
    private function scoredWords(SpeechFeatures $features): array
    {
        $words = [];

        foreach ($features->wordConfidences() as $word) {
            $display = trim($word['word']);

            if ($display === '') {
                continue;
            }

            $key = mb_strtolower($display);

            if (in_array($key, self::NOT_PRONUNCIATION, true)) {
                continue;
            }

            if (! isset($words[$key]) || $word['confidence'] < $words[$key]['confidence']) {
                $words[$key] = ['word' => $display, 'confidence' => round($word['confidence'], 3)];
            }
        }

        return $words;
    }

    /**
     * The least confident of the words that fell below the threshold, worst
     * first — then alphabetically, so two words at the same confidence do not
     * swap places between runs.
     *
     * @param  array<string, array{word: string, confidence: float}>  $scored
     * @return list<array{word: string, confidence: float}>
     */
    private function uncertainWords(array $scored): array
    {
        $uncertain = array_values(array_filter(
            $scored,
            static fn (array $word): bool => $word['confidence'] < self::UNCERTAIN_THRESHOLD,
        ));

        usort($uncertain, static function (array $a, array $b): int {
            return ($a['confidence'] <=> $b['confidence']) ?: strcmp($a['word'], $b['word']);
        });

        return array_slice($uncertain, 0, self::MAX_LISTED_WORDS);
    }
}
