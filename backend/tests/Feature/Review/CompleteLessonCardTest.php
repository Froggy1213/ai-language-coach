<?php

namespace Tests\Feature\Review;

use App\Enums\LessonCardStatus;
use App\Models\LessonCard;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class CompleteLessonCardTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    private const COMPLETE_CARD = /** @lang GraphQL */ '
        mutation ($lessonCardId: ID!) {
            completeLessonCard(lessonCardId: $lessonCardId) {
                id
                orderIndex
                status
                practicePrompt
            }
        }
    ';

    public function test_guests_cannot_complete_a_lesson_card(): void
    {
        $card = LessonCard::factory()->create(['status' => LessonCardStatus::Ready]);

        $this->graphQL(self::COMPLETE_CARD, ['lessonCardId' => $card->getKey()])
            ->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_happy_path_completes_ready_card_and_unlocks_next_card(): void
    {
        $user = User::factory()->create();
        $roadmap = Roadmap::factory()->for($user)->create();

        $card1 = LessonCard::factory()->for($roadmap)->create([
            'order_index' => 1,
            'status' => LessonCardStatus::Ready,
        ]);
        $card2 = LessonCard::factory()->for($roadmap)->create([
            'order_index' => 2,
            'status' => LessonCardStatus::Locked,
        ]);
        $card3 = LessonCard::factory()->for($roadmap)->create([
            'order_index' => 3,
            'status' => LessonCardStatus::Locked,
        ]);

        Sanctum::actingAs($user);

        $response = $this->graphQL(self::COMPLETE_CARD, ['lessonCardId' => $card1->getKey()]);

        $response->assertGraphQLErrorFree();
        $this->assertSame((string) $card1->id, $response->json('data.completeLessonCard.id'));
        $this->assertSame(LessonCardStatus::Completed->value, $response->json('data.completeLessonCard.status'));

        // DB verification: card 1 completed, card 2 ready, card 3 stays locked
        $card1->refresh();
        $card2->refresh();
        $card3->refresh();

        $this->assertSame(LessonCardStatus::Completed, $card1->status);
        $this->assertSame(LessonCardStatus::Ready, $card2->status);
        $this->assertSame(LessonCardStatus::Locked, $card3->status);
    }

    public function test_completing_the_last_card_of_a_roadmap_succeeds_without_a_next_card(): void
    {
        $user = User::factory()->create();
        $roadmap = Roadmap::factory()->for($user)->create();

        $lastCard = LessonCard::factory()->for($roadmap)->create([
            'order_index' => 5,
            'status' => LessonCardStatus::Ready,
        ]);

        Sanctum::actingAs($user);

        $response = $this->graphQL(self::COMPLETE_CARD, ['lessonCardId' => $lastCard->getKey()]);

        $response->assertGraphQLErrorFree();
        $this->assertSame(LessonCardStatus::Completed->value, $response->json('data.completeLessonCard.status'));

        $lastCard->refresh();
        $this->assertSame(LessonCardStatus::Completed, $lastCard->status);
    }

    public function test_ownership_refusal_learner_cannot_complete_another_users_card(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $roadmap = Roadmap::factory()->for($owner)->create();

        $card = LessonCard::factory()->for($roadmap)->create([
            'order_index' => 1,
            'status' => LessonCardStatus::Ready,
        ]);

        Sanctum::actingAs($otherUser);

        $response = $this->graphQL(self::COMPLETE_CARD, ['lessonCardId' => $card->getKey()]);

        $response->assertGraphQLErrorMessage('This lesson card does not exist.');
        $this->assertSame('LESSON_CARD_NOT_FOUND', $response->json('errors.0.extensions.code'));

        $card->refresh();
        $this->assertSame(LessonCardStatus::Ready, $card->status);
    }

    public function test_refuses_to_complete_a_locked_card(): void
    {
        $user = User::factory()->create();
        $roadmap = Roadmap::factory()->for($user)->create();

        $card = LessonCard::factory()->for($roadmap)->create([
            'order_index' => 2,
            'status' => LessonCardStatus::Locked,
        ]);

        Sanctum::actingAs($user);

        $response = $this->graphQL(self::COMPLETE_CARD, ['lessonCardId' => $card->getKey()]);

        $response->assertGraphQLErrorMessage('This lesson card is locked.');
        $this->assertSame('LESSON_CARD_LOCKED', $response->json('errors.0.extensions.code'));

        $card->refresh();
        $this->assertSame(LessonCardStatus::Locked, $card->status);
    }

    public function test_idempotent_repeated_completion_leaves_card_completed_and_returns_it(): void
    {
        $user = User::factory()->create();
        $roadmap = Roadmap::factory()->for($user)->create();

        $card1 = LessonCard::factory()->for($roadmap)->create([
            'order_index' => 1,
            'status' => LessonCardStatus::Completed,
        ]);
        $card2 = LessonCard::factory()->for($roadmap)->create([
            'order_index' => 2,
            'status' => LessonCardStatus::Ready,
        ]);

        Sanctum::actingAs($user);

        // Calling completion on an already completed card
        $response = $this->graphQL(self::COMPLETE_CARD, ['lessonCardId' => $card1->getKey()]);

        $response->assertGraphQLErrorFree();
        $this->assertSame(LessonCardStatus::Completed->value, $response->json('data.completeLessonCard.status'));

        $card1->refresh();
        $card2->refresh();
        $this->assertSame(LessonCardStatus::Completed, $card1->status);
        $this->assertSame(LessonCardStatus::Ready, $card2->status);
    }

    public function test_repeated_completion_ensures_next_card_is_unlocked_if_previously_locked(): void
    {
        $user = User::factory()->create();
        $roadmap = Roadmap::factory()->for($user)->create();

        $card1 = LessonCard::factory()->for($roadmap)->create([
            'order_index' => 1,
            'status' => LessonCardStatus::Completed,
        ]);
        $card2 = LessonCard::factory()->for($roadmap)->create([
            'order_index' => 2,
            'status' => LessonCardStatus::Locked,
        ]);

        Sanctum::actingAs($user);

        $response = $this->graphQL(self::COMPLETE_CARD, ['lessonCardId' => $card1->getKey()]);

        $response->assertGraphQLErrorFree();
        $card2->refresh();
        $this->assertSame(LessonCardStatus::Ready, $card2->status);
    }

    public function test_refuses_nonexistent_lesson_card(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->graphQL(self::COMPLETE_CARD, ['lessonCardId' => '999999']);

        $response->assertGraphQLErrorMessage('This lesson card does not exist.');
        $this->assertSame('LESSON_CARD_NOT_FOUND', $response->json('errors.0.extensions.code'));
    }
}
