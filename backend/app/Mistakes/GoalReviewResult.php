<?php

namespace App\Mistakes;

/**
 * The outcome of reviewing a scenario session against its learner goal (plan §9).
 *
 * Kept apart from {@see MistakeAnalysisResult}: mistakes are about grammar, this
 * is about whether the learner actually did the thing the scenario asked for.
 */
final readonly class GoalReviewResult
{
    /**
     * @param  list<string>  $missedExpressions
     */
    public function __construct(
        public bool $goalAchieved,
        public array $missedExpressions,
    ) {}
}
