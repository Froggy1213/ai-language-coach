<?php

namespace App\Scenarios;

use RuntimeException;

/**
 * The learner's custom scenario prompt could not be expanded into a role-play
 * card by the LLM.
 */
class ScenarioDraftFailed extends RuntimeException {}
