<?php

namespace App\Mistakes;

final class MistakeAnalysisResult
{
    /**
     * @param  list<AnalyzedMistake>  $mistakes
     */
    public function __construct(
        public readonly array $mistakes,
    ) {}
}
