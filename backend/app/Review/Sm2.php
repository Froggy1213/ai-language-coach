<?php

namespace App\Review;

use App\Models\ReviewItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Pure SuperMemo 2 (SM-2) spaced repetition algorithm implementation.
 */
final class Sm2
{
    public const DEFAULT_EASE_FACTOR = 2.50;

    public const DEFAULT_INTERVAL_DAYS = 1;

    public const DEFAULT_REPETITION_NUMBER = 0;

    public const MIN_EASE_FACTOR = 1.30;

    /**
     * Compute the next SM-2 review parameters.
     *
     * @param  int  $quality  Recall quality score from 0 (complete blackout) to 5 (perfect recall).
     * @param  float  $easeFactor  Current ease factor (>= 1.30, default 2.50).
     * @param  int  $intervalDays  Current interval in days (>= 1).
     * @param  int  $repetitionNumber  Number of consecutive successful reviews (>= 0).
     * @param  CarbonInterface|null  $now  Reference point for calculating next review date.
     */
    public function calculate(
        int $quality,
        float $easeFactor,
        int $intervalDays,
        int $repetitionNumber,
        ?CarbonInterface $now = null,
    ): Sm2Result {
        if ($quality < 0 || $quality > 5) {
            throw new InvalidArgumentException("Quality score must be between 0 and 5, got {$quality}.");
        }

        $baseTime = $now ?? Carbon::now();

        // 1. Calculate new Ease Factor:
        // EF' = EF + (0.1 - (5 - q) * (0.08 + (5 - q) * 0.02))
        $delta = 0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02);
        $newEaseFactor = round($easeFactor + $delta, 2);

        // Standard SM-2 enforces an ease factor floor of 1.30.
        if ($newEaseFactor < self::MIN_EASE_FACTOR) {
            $newEaseFactor = self::MIN_EASE_FACTOR;
        }

        // 2. Interval and repetition ladder:
        if ($quality < 3) {
            // Quality < 3 indicates a failure to recall:
            // The repetition count resets to 0, and the interval resets to 1 day.
            $newRepetitionNumber = 0;
            $newIntervalDays = 1;
        } else {
            // Successful recall (q >= 3):
            if ($repetitionNumber === 0) {
                $newIntervalDays = 1;
                $newRepetitionNumber = 1;
            } elseif ($repetitionNumber === 1) {
                $newIntervalDays = 6;
                $newRepetitionNumber = 2;
            } else {
                $newIntervalDays = (int) round($intervalDays * $newEaseFactor);
                if ($newIntervalDays < 1) {
                    $newIntervalDays = 1;
                }
                $newRepetitionNumber = $repetitionNumber + 1;
            }
        }

        $nextReviewAt = $baseTime->copy()->addDays($newIntervalDays);

        return new Sm2Result(
            easeFactor: $newEaseFactor,
            intervalDays: $newIntervalDays,
            repetitionNumber: $newRepetitionNumber,
            nextReviewAt: $nextReviewAt,
        );
    }

    /**
     * Apply SM-2 calculation directly to a ReviewItem model and persist it.
     */
    public function apply(ReviewItem $item, int $quality, ?CarbonInterface $now = null): ReviewItem
    {
        $result = $this->calculate(
            quality: $quality,
            easeFactor: (float) $item->ease_factor,
            intervalDays: (int) $item->interval_days,
            repetitionNumber: (int) $item->repetition_number,
            now: $now,
        );

        $item->ease_factor = $result->easeFactor;
        $item->interval_days = $result->intervalDays;
        $item->repetition_number = $result->repetitionNumber;
        $item->next_review_at = $result->nextReviewAt;
        $item->save();

        return $item;
    }
}
