<?php

namespace App\Assessments;

use RuntimeException;

/**
 * The uploaded object cannot be used for an assessment: wrong learner, missing,
 * oversized or a content type the pipeline cannot transcribe.
 *
 * The GraphQL mutations translate this into a validation error on `audioUrl`,
 * which keeps HTTP concerns out of the storage layer.
 */
class InvalidAssessmentAudio extends RuntimeException {}
