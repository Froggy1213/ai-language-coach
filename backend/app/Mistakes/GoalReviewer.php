<?php

namespace App\Mistakes;

use App\Models\VoiceSession;

/**
 * Boundary to the LLM that reviews a scenario session's communication (plan §9).
 *
 * This is deliberately a second prompt rather than an extra field on the
 * mistake analysis: it judges whether the learner achieved the scenario's goal,
 * not how grammatical their utterances were, and the two judgements must not
 * collapse into one error list.
 */
interface GoalReviewer
{
    /**
     * Judge the learner's communication against the scenario's learner goal.
     *
     * @throws GoalReviewFailed when the session has no scenario, the scenario is
     *                          not in the catalogue, there is no learner speech,
     *                          or the model produces an unusable response.
     */
    public function review(VoiceSession $session): GoalReviewResult;
}
