<?php

namespace App\GraphQL\Resolvers;

use App\Enums\AssessmentStatus;
use App\Models\Assessment;
use App\Models\User;

/**
 * The learner's most recent finished assessment.
 *
 * The level screen shows the pronunciation evidence for the recording it just
 * took, and that arrives over the `assessmentReady` subscription. A page reload
 * has no subscription, so without a way to read the result back the section would
 * disappear on the next visit — and a learner who closed the tab mid-analysis
 * would never see it at all. `User.assessments` has no more than a handful of
 * rows per learner, so the latest is picked with an ordered `first()` rather than
 * a column kept in sync.
 *
 * A status of `done` is required: an assessment still `processing` has no level
 * to show, and one that `failed` is a job to retry, not a result to display.
 */
final class LatestAssessment
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(User $user, array $args): ?Assessment
    {
        return $user->assessments()
            ->where('status', AssessmentStatus::Done)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }
}
