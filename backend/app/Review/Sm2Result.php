<?php

namespace App\Review;

use Carbon\CarbonInterface;

final readonly class Sm2Result
{
    public function __construct(
        public float $easeFactor,
        public int $intervalDays,
        public int $repetitionNumber,
        public CarbonInterface $nextReviewAt,
    ) {}
}
