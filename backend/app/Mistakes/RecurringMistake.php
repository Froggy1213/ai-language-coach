<?php

namespace App\Mistakes;

use App\Models\GrammarPoint;
use Carbon\CarbonImmutable;

/**
 * Value object representing a grammar topic repeatedly failed by a learner (plan §3, §7).
 */
final readonly class RecurringMistake
{
    public function __construct(
        public int $grammarPointId,
        public GrammarPoint $grammarPoint,
        public int $sessionCount,
        public int $mistakeCount,
        public CarbonImmutable $lastMistakeAt,
    ) {}
}
