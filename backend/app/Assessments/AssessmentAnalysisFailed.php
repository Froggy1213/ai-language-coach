<?php

namespace App\Assessments;

use RuntimeException;

/**
 * The transcript could not be turned into a CEFR judgement.
 */
class AssessmentAnalysisFailed extends RuntimeException {}
