<?php

namespace App\Mistakes;

use RuntimeException;

/**
 * The scenario session's communication could not be reviewed by the LLM.
 *
 * Best-effort by design: the caller keeps the grammar mistakes and still
 * broadcasts when this is thrown, so a failed review never costs the learner
 * the rest of their feedback.
 */
class GoalReviewFailed extends RuntimeException {}
