<?php

namespace Tests\Feature\GraphQL;

use App\Enums\CefrLevel;
use App\Models\Roadmap;
use App\Models\User;
use Database\Seeders\GrammarPointSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class GenerateRoadmapMutationTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    private const GENERATE_ROADMAP = /** @lang GraphQL */ '
        mutation {
            generateRoadmap {
                id
                title
                status
                lessonCards {
                    id
                    orderIndex
                    status
                    practicePrompt
                    cheatSheet { rule formula examples pitfalls }
                    grammarPoint { id code }
                }
            }
        }
    ';

    public function test_guests_cannot_generate_a_roadmap(): void
    {
        $this->seed(GrammarPointSeeder::class);

        $this->graphQL(self::GENERATE_ROADMAP)
            ->assertGraphQLErrorMessage('Unauthenticated.');

        $this->assertDatabaseCount('roadmaps', 0);
    }

    public function test_it_generates_the_roadmap_of_the_authenticated_user(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::B1]);

        Sanctum::actingAs($user);

        $response = $this->graphQL(self::GENERATE_ROADMAP)->assertGraphQLErrorFree();

        $response
            ->assertJsonPath('data.generateRoadmap.title', 'English · A1–B1')
            ->assertJsonPath('data.generateRoadmap.status', 'active')
            ->assertJsonPath('data.generateRoadmap.lessonCards.0.orderIndex', 1)
            ->assertJsonPath('data.generateRoadmap.lessonCards.0.status', 'ready')
            ->assertJsonPath('data.generateRoadmap.lessonCards.0.grammarPoint.code', 'present_simple')
            ->assertJsonPath('data.generateRoadmap.lessonCards.1.status', 'locked')
            ->assertJsonPath('data.generateRoadmap.lessonCards.1.orderIndex', 2);

        $this->assertNotSame('', $response->json('data.generateRoadmap.lessonCards.0.practicePrompt'));
        $this->assertNotSame([], $response->json('data.generateRoadmap.lessonCards.0.cheatSheet.examples'));
        $this->assertNotSame([], $response->json('data.generateRoadmap.lessonCards.0.cheatSheet.pitfalls'));

        $roadmap = Roadmap::query()->sole();
        $this->assertTrue($roadmap->user->is($user));
        $this->assertSame($roadmap->lessonCards->count(), count($response->json('data.generateRoadmap.lessonCards')));
    }

    public function test_retrying_returns_the_same_roadmap(): void
    {
        $this->seed(GrammarPointSeeder::class);
        Sanctum::actingAs(User::factory()->create(['target_language' => 'en']));

        $first = $this->graphQL(self::GENERATE_ROADMAP)->assertGraphQLErrorFree();
        $second = $this->graphQL(self::GENERATE_ROADMAP)->assertGraphQLErrorFree();

        $this->assertSame(
            $first->json('data.generateRoadmap.id'),
            $second->json('data.generateRoadmap.id'),
        );
        $this->assertDatabaseCount('roadmaps', 1);
    }

    public function test_it_does_not_generate_a_roadmap_for_anyone_else(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'en']);
        $otherUser = User::factory()->create(['target_language' => 'en']);

        Sanctum::actingAs($user);

        $this->graphQL(self::GENERATE_ROADMAP)->assertGraphQLErrorFree();

        $this->assertDatabaseMissing('roadmaps', ['user_id' => $otherUser->id]);
        $this->assertDatabaseHas('roadmaps', ['user_id' => $user->id]);
    }
}
