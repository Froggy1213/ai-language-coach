<?php

namespace App\Assessments;

/**
 * How much of a recording the recogniser was confident about, word by word.
 *
 * There is no phoneme measurement behind this and it must never be presented as
 * one. The repository's own survey of the vendor landscape
 * (`benchmarks/research/voice-stack-research-2026-10-14.md`) reaches the same
 * conclusion from the published evidence: a hosted speech model asked to grade
 * pronunciation outright correlates with human raters at 0.05–0.21, and the
 * recommended shape for a learner-facing feature is to compute the evidence
 * separately and let a language model only phrase it. That is what this class
 * carries — evidence — and `advice`, the sentence the model wrote about it.
 *
 * So the numbers are explicitly *about the audio*: how often, and on which
 * words, the recogniser was unsure. A microphone across the room, background
 * noise, an unfamiliar accent and a word said unclearly all move it, and the
 * learner is told that in the interface rather than being handed a grade.
 *
 * @see PronunciationAnalyzer for the thresholds and what each band means.
 */
final readonly class PronunciationAssessment
{
    public const BAND_CLEAR = 'clear';

    public const BAND_MOSTLY_CLEAR = 'mostly_clear';

    public const BAND_UNEVEN = 'uneven';

    public const BAND_EFFORTFUL = 'effortful';

    /**
     * @param  string|null  $band  One of the `BAND_*` constants, or null when the sample was too short to judge.
     * @param  int|null  $clarityPercent  Share of words the recogniser was confident about, whole percent.
     * @param  list<array{word: string, confidence: float}>  $uncertainWords  The least confident words, worst first.
     * @param  bool  $isThinSample  Whether the recording was too short to report a band from.
     * @param  string|null  $advice  What the analysis wrote for the learner, in the interface language.
     */
    public function __construct(
        public ?string $band,
        public ?int $clarityPercent,
        public array $uncertainWords,
        public bool $isThinSample,
        public float $uncertainThreshold,
        public ?string $advice = null,
    ) {}

    /**
     * The same evidence, with the sentence the analysis wrote attached.
     *
     * The analyzer has no language model in it on purpose — the measurement is
     * arithmetic over a provider payload and must not depend on a prompt — so the
     * wording is added afterwards by whoever ran the analysis.
     */
    public function withAdvice(?string $advice): self
    {
        return new self(
            band: $this->band,
            clarityPercent: $this->clarityPercent,
            uncertainWords: $this->uncertainWords,
            isThinSample: $this->isThinSample,
            uncertainThreshold: $this->uncertainThreshold,
            advice: $advice,
        );
    }

    /**
     * Shape stored under `assessments.raw_data.pronunciation`.
     *
     * Written key by key rather than filtered: `is_thin_sample` is a boolean the
     * client reads, and a filter that drops falsy values would silently omit it
     * for every recording that was long enough — the field would then be absent
     * exactly when it is most useful. `band` and `clarity_percent` are null for a
     * thin sample, which is how the interface learns to say nothing about the
     * overall clarity rather than having to apply the rule itself.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'band' => $this->band,
            'clarity_percent' => $this->clarityPercent,
            'uncertain_threshold' => round($this->uncertainThreshold, 2),
            'uncertain_words' => $this->uncertainWords,
            'is_thin_sample' => $this->isThinSample,
            'advice' => $this->advice,
        ];
    }
}
