<?php

namespace App\Mistakes;

use App\Models\GrammarPoint;

final class AnalyzedMistake
{
    public function __construct(
        public readonly GrammarPoint $grammarPoint,
        public readonly string $userUtterance,
        public readonly string $correction,
        public readonly string $explanation,
    ) {}
}
