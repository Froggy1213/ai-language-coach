<?php

namespace App\Assessments;

use App\Enums\CefrLevel;

/**
 * The CEFR judgement the analysis reached, with the reasoning the learner will
 * eventually see on the results screen.
 */
final readonly class AssessmentResult
{
    /**
     * @param  list<string>  $strengths
     * @param  list<string>  $weaknesses
     * @param  PronunciationAssessment|null  $pronunciation  The confidence evidence, with the sentence the analysis wrote about it.
     */
    public function __construct(
        public CefrLevel $level,
        public string $summary,
        public array $strengths,
        public array $weaknesses,
        public ?PronunciationAssessment $pronunciation = null,
    ) {}

    /**
     * The same judgement with the pronunciation evidence attached.
     *
     * The evidence is measured before the model is called and the model only
     * contributes the sentence, so whoever holds both halves joins them here
     * rather than in the job.
     */
    public function withPronunciation(?PronunciationAssessment $pronunciation): self
    {
        return new self(
            level: $this->level,
            summary: $this->summary,
            strengths: $this->strengths,
            weaknesses: $this->weaknesses,
            pronunciation: $pronunciation,
        );
    }

    /**
     * Shape stored under `assessments.raw_data.analysis`.
     *
     * @return array{cefr_level: string, summary: string, strengths: list<string>, weaknesses: list<string>}
     */
    public function toArray(): array
    {
        return [
            'cefr_level' => $this->level->value,
            'summary' => $this->summary,
            'strengths' => $this->strengths,
            'weaknesses' => $this->weaknesses,
        ];
    }
}
