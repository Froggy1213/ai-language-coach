<?php

namespace App\Assessments;

use App\Models\User;

/**
 * Boundary to the LLM that judges a transcript against the CEFR bands
 * (plan §5: DeepSeek-V3, where latency does not matter).
 *
 * The contract exists so the analysis job can be tested without a language
 * model in the loop, and so the model can be swapped after the November
 * first-token-latency benchmark without touching the pipeline.
 */
interface CefrAssessor
{
    /**
     * @throws AssessmentAnalysisFailed when the model cannot produce a usable judgement.
     */
    public function assess(Transcription $transcription, User $user): AssessmentResult;
}
