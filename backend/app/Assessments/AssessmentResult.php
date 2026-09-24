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
     */
    public function __construct(
        public CefrLevel $level,
        public string $summary,
        public array $strengths,
        public array $weaknesses,
    ) {}

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
