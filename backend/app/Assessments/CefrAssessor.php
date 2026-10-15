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
     * @param  PronunciationAssessment  $pronunciation  The confidence evidence the model is asked to put into words.
     * @param  PronunciationAnalyzer  $analyzer  Renders the evidence into the prompt block; the analyzer owns that shape.
     *
     * @throws AssessmentAnalysisFailed when the model cannot produce a usable judgement.
     */
    public function assess(
        Transcription $transcription,
        User $user,
        PronunciationAssessment $pronunciation,
        PronunciationAnalyzer $analyzer,
    ): AssessmentResult;
}
