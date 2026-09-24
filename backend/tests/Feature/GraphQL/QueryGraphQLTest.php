<?php

namespace Tests\Feature\GraphQL;

use App\Enums\CefrLevel;
use App\Enums\LessonCardStatus;
use App\Enums\RoadmapStatus;
use App\Models\GrammarPoint;
use App\Models\LessonCard;
use App\Models\Mistake;
use App\Models\ReviewItem;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class QueryGraphQLTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada',
            'target_language' => 'en',
            'current_level' => CefrLevel::B1,
        ]);

        Sanctum::actingAs($user);

        $this->graphQL(/** @lang GraphQL */ '
            query {
                me {
                    id
                    name
                    targetLanguage
                    currentLevel
                }
            }
        ')
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.me.id', (string) $user->id)
            ->assertJsonPath('data.me.name', 'Ada')
            ->assertJsonPath('data.me.targetLanguage', 'en')
            ->assertJsonPath('data.me.currentLevel', 'B1');
    }

    public function test_roadmap_returns_cards_in_study_order(): void
    {
        $user = User::factory()->create();
        $roadmap = Roadmap::factory()->for($user)->create(['title' => 'A1 foundations']);
        $later = LessonCard::factory()->for($roadmap)->create([
            'order_index' => 2,
            'status' => LessonCardStatus::Locked,
        ]);
        $earlier = LessonCard::factory()->for($roadmap)->create([
            'order_index' => 1,
            'status' => LessonCardStatus::Ready,
        ]);

        Sanctum::actingAs($user);

        $this->graphQL(/** @lang GraphQL */ '
            query {
                roadmap {
                    id
                    title
                    status
                    lessonCards {
                        id
                        orderIndex
                        status
                        cheatSheet { rule formula examples pitfalls }
                        grammarPoint { id language code title category }
                    }
                }
            }
        ')
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.roadmap.title', 'A1 foundations')
            ->assertJsonPath('data.roadmap.status', 'active')
            ->assertJsonPath('data.roadmap.lessonCards.0.id', (string) $earlier->id)
            ->assertJsonPath('data.roadmap.lessonCards.0.status', 'ready')
            ->assertJsonPath('data.roadmap.lessonCards.1.id', (string) $later->id)
            ->assertJsonCount(2, 'data.roadmap.lessonCards');
    }

    public function test_roadmap_returns_the_active_plan_when_an_older_one_was_archived(): void
    {
        $user = User::factory()->create();
        Roadmap::factory()->for($user)->create(['title' => 'Old plan', 'status' => RoadmapStatus::Archived]);
        $active = Roadmap::factory()->for($user)->create(['title' => 'Current plan', 'status' => RoadmapStatus::Active]);

        Sanctum::actingAs($user);

        $this->graphQL(/** @lang GraphQL */ '
            query {
                roadmap { id title status }
            }
        ')
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.roadmap.id', (string) $active->id)
            ->assertJsonPath('data.roadmap.title', 'Current plan')
            ->assertJsonPath('data.roadmap.status', 'active');
    }

    public function test_roadmap_does_not_leak_another_users_plan(): void
    {
        $user = User::factory()->create();
        Roadmap::factory()->create(['title' => 'Someone elses plan']);

        Sanctum::actingAs($user);

        $this->graphQL(/** @lang GraphQL */ '
            query {
                roadmap { id title }
            }
        ')
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.roadmap', null)
            ->assertDontSee('Someone elses plan');
    }

    public function test_due_reviews_cover_only_the_users_due_items(): void
    {
        $user = User::factory()->create();
        $older = ReviewItem::factory()->for($user)->create(['next_review_at' => now()->subWeek()]);
        $newer = ReviewItem::factory()->for($user)->create(['next_review_at' => now()->subDay()]);
        ReviewItem::factory()->for($user)->create(['next_review_at' => now()->addWeek()]);
        ReviewItem::factory()->create(['next_review_at' => now()->subWeek()]);

        Sanctum::actingAs($user);

        $this->graphQL(/** @lang GraphQL */ '
            query {
                dueReviews {
                    id
                    easeFactor
                    intervalDays
                    nextReviewAt
                    grammarPoint { id }
                }
            }
        ')
            ->assertGraphQLErrorFree()
            ->assertJsonCount(2, 'data.dueReviews')
            ->assertJsonPath('data.dueReviews.0.id', (string) $older->id)
            ->assertJsonPath('data.dueReviews.1.id', (string) $newer->id)
            ->assertJsonPath('data.dueReviews.0.easeFactor', 2.5);
    }

    public function test_mistakes_cover_only_the_users_own(): void
    {
        $user = User::factory()->create();
        $own = Mistake::factory()->for($user)->create(['correction' => 'I have eaten']);
        Mistake::factory()->create(['correction' => 'Someone elses correction']);

        Sanctum::actingAs($user);

        $this->graphQL(/** @lang GraphQL */ '
            query {
                mistakes {
                    id
                    userUtterance
                    correction
                    explanation
                    grammarPoint { id code }
                }
            }
        ')
            ->assertGraphQLErrorFree()
            ->assertJsonCount(1, 'data.mistakes')
            ->assertJsonPath('data.mistakes.0.id', (string) $own->id)
            ->assertJsonPath('data.mistakes.0.correction', 'I have eaten')
            ->assertDontSee('Someone elses correction');
    }

    public function test_mistakes_can_be_filtered_by_grammar_point(): void
    {
        $user = User::factory()->create();
        $grammarPoint = GrammarPoint::factory()->create();
        $matching = Mistake::factory()->for($user)->for($grammarPoint)->create();
        Mistake::factory()->for($user)->create();

        Sanctum::actingAs($user);

        $this->graphQL(/** @lang GraphQL */ '
            query ($grammarPointId: ID) {
                mistakes(grammarPointId: $grammarPointId) {
                    id
                    grammarPoint { id }
                }
            }
        ', ['grammarPointId' => (string) $grammarPoint->id])
            ->assertGraphQLErrorFree()
            ->assertJsonCount(1, 'data.mistakes')
            ->assertJsonPath('data.mistakes.0.id', (string) $matching->id);
    }

    public function test_mistakes_without_a_filter_return_every_mistake(): void
    {
        $user = User::factory()->create();
        Mistake::factory()->for($user)->count(3)->create();

        Sanctum::actingAs($user);

        $this->graphQL(/** @lang GraphQL */ '
            query ($grammarPointId: ID) {
                mistakes(grammarPointId: $grammarPointId) { id }
            }
        ', ['grammarPointId' => null])
            ->assertGraphQLErrorFree()
            ->assertJsonCount(3, 'data.mistakes');
    }

    public function test_native_enums_are_exposed_as_graphql_enums(): void
    {
        $expected = [
            'CefrLevel' => ['A1', 'A2', 'B1', 'B2', 'C1'],
            'RoadmapStatus' => ['active', 'completed', 'archived'],
            'LessonCardStatus' => ['locked', 'ready', 'completed'],
        ];

        foreach ($expected as $typeName => $values) {
            $type = $this->introspectType($typeName);

            // A registered enum that never got registered degrades into a
            // dummy custom scalar, which introspection would still return.
            $this->assertSame('ENUM', $type['kind'] ?? null, "{$typeName} is not exposed as an enum");
            $this->assertSame($values, array_column($type['enumValues'], 'name'));
        }
    }
}
