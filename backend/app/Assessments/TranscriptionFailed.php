<?php

namespace App\Assessments;

use RuntimeException;

/**
 * The recording could not be turned into text the analysis could use.
 */
class TranscriptionFailed extends RuntimeException {}
