<?php

namespace Tests\Unit\Review;

use App\Review\Sm2;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Sm2Test extends TestCase
{
    private Sm2 $sm2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sm2 = new Sm2;
    }

    public function test_first_successful_review_sets_interval_to_one_day(): void
    {
        $now = Carbon::parse('2026-10-02 12:00:00');

        // Initial state of a review item: rep = 0, interval = 1, ease_factor = 2.50
        $result = $this->sm2->calculate(
            quality: 4,
            easeFactor: 2.50,
            intervalDays: 1,
            repetitionNumber: 0,
            now: $now,
        );

        $this->assertSame(1, $result->intervalDays);
        $this->assertSame(1, $result->repetitionNumber);
        $this->assertSame(2.50, $result->easeFactor);
        $this->assertSame('2026-10-03 12:00:00', $result->nextReviewAt->format('Y-m-d H:i:s'));
    }

    public function test_second_successful_review_sets_interval_to_six_days(): void
    {
        $now = Carbon::parse('2026-10-02 12:00:00');

        $result = $this->sm2->calculate(
            quality: 4,
            easeFactor: 2.50,
            intervalDays: 1,
            repetitionNumber: 1,
            now: $now,
        );

        $this->assertSame(6, $result->intervalDays);
        $this->assertSame(2, $result->repetitionNumber);
        $this->assertSame(2.50, $result->easeFactor);
        $this->assertSame('2026-10-08 12:00:00', $result->nextReviewAt->format('Y-m-d H:i:s'));
    }

    public function test_subsequent_successful_reviews_scale_interval_by_ease_factor(): void
    {
        $now = Carbon::parse('2026-10-02 12:00:00');

        // repetition 2 -> 3: interval = round(6 * 2.50) = 15
        $result1 = $this->sm2->calculate(
            quality: 4,
            easeFactor: 2.50,
            intervalDays: 6,
            repetitionNumber: 2,
            now: $now,
        );

        $this->assertSame(15, $result1->intervalDays);
        $this->assertSame(3, $result1->repetitionNumber);
        $this->assertSame(2.50, $result1->easeFactor);
        $this->assertSame('2026-10-17 12:00:00', $result1->nextReviewAt->format('Y-m-d H:i:s'));

        // repetition 3 -> 4: interval = round(15 * 2.50) = 38 (37.5 rounds to 38)
        $result2 = $this->sm2->calculate(
            quality: 4,
            easeFactor: 2.50,
            intervalDays: 15,
            repetitionNumber: 3,
            now: $now,
        );

        $this->assertSame(38, $result2->intervalDays);
        $this->assertSame(4, $result2->repetitionNumber);
    }

    /**
     * @return array<string, array{int, float, float}>
     */
    public static function easeFactorAdjustments(): array
    {
        return [
            'quality 5 increases EF by 0.10' => [5, 2.50, 2.60],
            'quality 4 keeps EF unchanged' => [4, 2.50, 2.50],
            'quality 3 decreases EF by 0.14' => [3, 2.50, 2.36],
            'quality 2 decreases EF by 0.32' => [2, 2.50, 2.18],
            'quality 1 decreases EF by 0.54' => [1, 2.50, 1.96],
            'quality 0 decreases EF by 0.80' => [0, 2.50, 1.70],
        ];
    }

    #[DataProvider('easeFactorAdjustments')]
    public function test_ease_factor_adjusts_according_to_sm2_formula(int $quality, float $initialEf, float $expectedEf): void
    {
        $result = $this->sm2->calculate(
            quality: $quality,
            easeFactor: $initialEf,
            intervalDays: 6,
            repetitionNumber: 2,
        );

        $this->assertEqualsWithDelta($expectedEf, $result->easeFactor, 0.001);
    }

    public function test_quality_below_three_resets_repetition_and_interval(): void
    {
        $now = Carbon::parse('2026-10-02 12:00:00');

        // Started with repetition 5 and 60 days interval
        $result = $this->sm2->calculate(
            quality: 2,
            easeFactor: 2.50,
            intervalDays: 60,
            repetitionNumber: 5,
            now: $now,
        );

        $this->assertSame(0, $result->repetitionNumber);
        $this->assertSame(1, $result->intervalDays);
        $this->assertEqualsWithDelta(2.18, $result->easeFactor, 0.001);
        $this->assertSame('2026-10-03 12:00:00', $result->nextReviewAt->format('Y-m-d H:i:s'));

        // Quality 0 also resets
        $resultZero = $this->sm2->calculate(
            quality: 0,
            easeFactor: 2.50,
            intervalDays: 60,
            repetitionNumber: 5,
            now: $now,
        );

        $this->assertSame(0, $resultZero->repetitionNumber);
        $this->assertSame(1, $resultZero->intervalDays);
        $this->assertEqualsWithDelta(1.70, $resultZero->easeFactor, 0.001);
    }

    public function test_ease_factor_cannot_drop_below_minimum_floor_of_1_30(): void
    {
        // Initial EF of 1.40 with quality 0 would drop by 0.80 to 0.60
        $result = $this->sm2->calculate(
            quality: 0,
            easeFactor: 1.40,
            intervalDays: 10,
            repetitionNumber: 3,
        );

        $this->assertSame(Sm2::MIN_EASE_FACTOR, $result->easeFactor);
        $this->assertSame(1.30, $result->easeFactor);

        // When already at floor 1.30, failing quality still respects floor
        $resultAtFloor = $this->sm2->calculate(
            quality: 2,
            easeFactor: 1.30,
            intervalDays: 1,
            repetitionNumber: 0,
        );

        $this->assertSame(1.30, $resultAtFloor->easeFactor);
    }

    public function test_ease_factor_at_floor_can_recover_with_perfect_reviews(): void
    {
        $result = $this->sm2->calculate(
            quality: 5,
            easeFactor: 1.30,
            intervalDays: 1,
            repetitionNumber: 0,
        );

        $this->assertEqualsWithDelta(1.40, $result->easeFactor, 0.001);
    }

    public function test_it_rejects_negative_quality(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quality score must be between 0 and 5, got -1.');

        $this->sm2->calculate(
            quality: -1,
            easeFactor: 2.50,
            intervalDays: 1,
            repetitionNumber: 0,
        );
    }

    public function test_it_rejects_quality_greater_than_five(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quality score must be between 0 and 5, got 6.');

        $this->sm2->calculate(
            quality: 6,
            easeFactor: 2.50,
            intervalDays: 1,
            repetitionNumber: 0,
        );
    }
}
