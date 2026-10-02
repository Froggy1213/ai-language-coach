<?php

namespace Tests\Feature\Review;

use App\Models\GrammarPoint;
use App\Models\ReviewItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class SubmitReviewResultTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    private const SUBMIT_REVIEW = /** @lang GraphQL */ '
        mutation ($grammarPointId: ID!, $quality: Int!) {
            submitReviewResult(grammarPointId: $grammarPointId, quality: $quality) {
                id
                easeFactor
                intervalDays
                repetitionNumber
                nextReviewAt
                grammarPoint {
                    id
                }
            }
        }
    ';

    public function test_guests_cannot_submit_review_result(): void
    {
        $gp = GrammarPoint::factory()->create();

        $this->graphQL(self::SUBMIT_REVIEW, [
            'grammarPointId' => $gp->getKey(),
            'quality' => 4,
        ])->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_learner_can_submit_first_successful_review(): void
    {
        Carbon::setTestNow('2026-10-02 12:00:00');

        $user = User::factory()->create();
        $gp = GrammarPoint::factory()->create();
        $item = ReviewItem::factory()->for($user)->for($gp)->create([
            'ease_factor' => 2.50,
            'interval_days' => 1,
            'repetition_number' => 0,
            'next_review_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->graphQL(self::SUBMIT_REVIEW, [
            'grammarPointId' => $gp->getKey(),
            'quality' => 4,
        ]);

        $response->assertGraphQLErrorFree();
        $this->assertSame((string) $item->id, $response->json('data.submitReviewResult.id'));
        $this->assertSame(2.5, $response->json('data.submitReviewResult.easeFactor'));
        $this->assertSame(1, $response->json('data.submitReviewResult.intervalDays'));
        $this->assertSame(1, $response->json('data.submitReviewResult.repetitionNumber'));
        $this->assertSame('2026-10-03 12:00:00', $response->json('data.submitReviewResult.nextReviewAt'));

        $item->refresh();
        $this->assertSame('2.50', (string) $item->ease_factor);
        $this->assertSame(1, $item->interval_days);
        $this->assertSame(1, $item->repetition_number);
    }

    public function test_subsequent_successful_reviews_follow_sm2_ladder(): void
    {
        Carbon::setTestNow('2026-10-02 12:00:00');

        $user = User::factory()->create();
        $gp = GrammarPoint::factory()->create();
        $item = ReviewItem::factory()->for($user)->for($gp)->create([
            'ease_factor' => 2.50,
            'interval_days' => 1,
            'repetition_number' => 1,
            'next_review_at' => now(),
        ]);

        Sanctum::actingAs($user);

        // Second repetition -> interval = 6 days, EF increases by 0.10 for q=5
        $response = $this->graphQL(self::SUBMIT_REVIEW, [
            'grammarPointId' => $gp->getKey(),
            'quality' => 5,
        ]);

        $response->assertGraphQLErrorFree();
        $this->assertSame(2.6, $response->json('data.submitReviewResult.easeFactor'));
        $this->assertSame(6, $response->json('data.submitReviewResult.intervalDays'));
        $this->assertSame(2, $response->json('data.submitReviewResult.repetitionNumber'));
        $this->assertSame('2026-10-08 12:00:00', $response->json('data.submitReviewResult.nextReviewAt'));

        $item->refresh();
        $this->assertSame('2.60', (string) $item->ease_factor);
        $this->assertSame(6, $item->interval_days);
        $this->assertSame(2, $item->repetition_number);
    }

    public function test_failing_quality_resets_repetition_ladder_and_interval(): void
    {
        Carbon::setTestNow('2026-10-02 12:00:00');

        $user = User::factory()->create();
        $gp = GrammarPoint::factory()->create();
        $item = ReviewItem::factory()->for($user)->for($gp)->create([
            'ease_factor' => 2.50,
            'interval_days' => 15,
            'repetition_number' => 3,
            'next_review_at' => now(),
        ]);

        Sanctum::actingAs($user);

        // Quality 2 represents failed recall (q < 3) -> interval resets to 1, rep to 0, EF drops by 0.32
        $response = $this->graphQL(self::SUBMIT_REVIEW, [
            'grammarPointId' => $gp->getKey(),
            'quality' => 2,
        ]);

        $response->assertGraphQLErrorFree();
        $this->assertSame(2.18, $response->json('data.submitReviewResult.easeFactor'));
        $this->assertSame(1, $response->json('data.submitReviewResult.intervalDays'));
        $this->assertSame(0, $response->json('data.submitReviewResult.repetitionNumber'));
        $this->assertSame('2026-10-03 12:00:00', $response->json('data.submitReviewResult.nextReviewAt'));

        $item->refresh();
        $this->assertSame('2.18', (string) $item->ease_factor);
        $this->assertSame(1, $item->interval_days);
        $this->assertSame(0, $item->repetition_number);
    }

    public function test_ownership_refusal_learner_cannot_review_another_users_item(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $gp = GrammarPoint::factory()->create();

        $ownerItem = ReviewItem::factory()->for($owner)->for($gp)->create([
            'ease_factor' => 2.50,
            'interval_days' => 6,
            'repetition_number' => 2,
        ]);

        Sanctum::actingAs($otherUser);

        $response = $this->graphQL(self::SUBMIT_REVIEW, [
            'grammarPointId' => $gp->getKey(),
            'quality' => 5,
        ]);

        $response->assertGraphQLErrorMessage('No review item exists for this grammar point.');
        $this->assertSame('REVIEW_ITEM_NOT_FOUND', $response->json('errors.0.extensions.code'));

        // Owner's item was not modified
        $ownerItem->refresh();
        $this->assertSame('2.50', (string) $ownerItem->ease_factor);
        $this->assertSame(6, $ownerItem->interval_days);
        $this->assertSame(2, $ownerItem->repetition_number);
    }

    public function test_missing_row_refusal_when_no_review_item_exists_for_grammar_point(): void
    {
        $user = User::factory()->create();
        $gp = GrammarPoint::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->graphQL(self::SUBMIT_REVIEW, [
            'grammarPointId' => $gp->getKey(),
            'quality' => 4,
        ]);

        $response->assertGraphQLErrorMessage('No review item exists for this grammar point.');
        $this->assertSame('REVIEW_ITEM_NOT_FOUND', $response->json('errors.0.extensions.code'));
    }

    public function test_refuses_nonexistent_grammar_point(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->graphQL(self::SUBMIT_REVIEW, [
            'grammarPointId' => '999999',
            'quality' => 4,
        ]);

        $response->assertGraphQLErrorMessage('This grammar point does not exist.');
        $this->assertSame('GRAMMAR_POINT_NOT_FOUND', $response->json('errors.0.extensions.code'));
    }

    public function test_rejects_quality_outside_valid_range(): void
    {
        $user = User::factory()->create();
        $gp = GrammarPoint::factory()->create();
        ReviewItem::factory()->for($user)->for($gp)->create();

        Sanctum::actingAs($user);

        $this->graphQL(self::SUBMIT_REVIEW, [
            'grammarPointId' => $gp->getKey(),
            'quality' => 6,
        ])->assertGraphQLValidationError('quality', 'The quality field must be between 0 and 5.');

        $this->graphQL(self::SUBMIT_REVIEW, [
            'grammarPointId' => $gp->getKey(),
            'quality' => -1,
        ])->assertGraphQLValidationError('quality', 'The quality field must be between 0 and 5.');
    }
}
