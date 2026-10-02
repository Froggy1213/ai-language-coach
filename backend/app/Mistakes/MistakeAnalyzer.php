<?php

namespace App\Mistakes;

use App\Models\User;

/**
 * Boundary to the LLM that analyzes a learner's voice session transcript
 * for grammatical mistakes constrained to the canonical grammar catalogue (plan §5).
 */
interface MistakeAnalyzer
{
    /**
     * @throws MistakeAnalysisFailed when the model produces an unusable response.
     */
    public function analyze(string $transcript, User $user): MistakeAnalysisResult;
}
