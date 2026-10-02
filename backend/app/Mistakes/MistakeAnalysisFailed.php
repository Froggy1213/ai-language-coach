<?php

namespace App\Mistakes;

use RuntimeException;

/**
 * The transcript could not be analyzed for mistakes by the LLM.
 */
class MistakeAnalysisFailed extends RuntimeException {}
