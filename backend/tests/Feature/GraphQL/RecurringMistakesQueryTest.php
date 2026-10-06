<?php

namespace Tests\Feature\GraphQL;

use App\Models\GrammarPoint;
use App\Models\Mistake;
use App\Models\User;
use App\Models\VoiceSession;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class RecurringMistakesQueryTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    private const QUERY = /** @lang GraphQL */ '
        query {
            recurringMistakes {
                grammarPoint {
                    id
                    title
                    category
                }
                sessionCount
                mistakeCount
                lastMistakeAt
            }
        }
    ';

    public function test_guest_is_rejected_by_guard(): void
    {
        $this->graphQL(self::QUERY)
            ->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_query_is_owner_scoped_and_does_not_leak_other_learners_mistakes(): void
    {
        Carbon::setTestNow('2026-10-06 12:00:00');

        $learnerA = User::factory()->create();
        $learnerB = User::factory()->create();

        $gpA = GrammarPoint::factory()->create(['title' => 'Learner A topic']);
        $gpB = GrammarPoint::factory()->create(['title' => 'Learner B topic']);

        // Learner A has 3 sessions for gpA
        $sessionsA = VoiceSession::factory()->for($learnerA)->count(3)->create();
        foreach ($sessionsA as $session) {
            Mistake::factory()->for($learnerA)->for($gpA)->create([
                'session_id' => $session->id,
                'created_at' => now()->subDay(),
            ]);
        }

        // Learner B has 3 sessions for gpB
        $sessionsB = VoiceSession::factory()->for($learnerB)->count(3)->create();
        foreach ($sessionsB as $session) {
            Mistake::factory()->for($learnerB)->for($gpB)->create([
                'session_id' => $session->id,
                'created_at' => now()->subDay(),
            ]);
        }

        Sanctum::actingAs($learnerA);

        $response = $this->graphQL(self::QUERY)
            ->assertGraphQLErrorFree();

        $data = $response->json('data.recurringMistakes');
        $this->assertCount(1, $data);
        $this->assertSame((string) $gpA->id, $data[0]['grammarPoint']['id']);
        $this->assertSame('Learner A topic', $data[0]['grammarPoint']['title']);
        $response->assertDontSee('Learner B topic');
    }

    public function test_exact_json_shape_and_payload_for_recurring_mistake(): void
    {
        Carbon::setTestNow('2026-10-06 12:00:00');

        $user = User::factory()->create();
        $grammarPoint = GrammarPoint::factory()->create([
            'title' => 'Past Continuous',
            'category' => 'tenses',
        ]);

        $session1 = VoiceSession::factory()->for($user)->create();
        $session2 = VoiceSession::factory()->for($user)->create();
        $session3 = VoiceSession::factory()->for($user)->create();

        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $session1->id,
            'created_at' => Carbon::parse('2026-10-03 10:00:00'),
        ]);
        Mistake::factory()->for($user)->for($grammarPoint)->count(2)->create([
            'session_id' => $session2->id,
            'created_at' => Carbon::parse('2026-10-04 11:00:00'),
        ]);
        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $session3->id,
            'created_at' => Carbon::parse('2026-10-05 15:30:00'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->graphQL(self::QUERY)
            ->assertGraphQLErrorFree();

        $response->assertExactJson([
            'data' => [
                'recurringMistakes' => [
                    [
                        'grammarPoint' => [
                            'id' => (string) $grammarPoint->id,
                            'title' => 'Past Continuous',
                            'category' => 'tenses',
                        ],
                        'sessionCount' => 3,
                        'mistakeCount' => 4,
                        'lastMistakeAt' => '2026-10-05 15:30:00',
                    ],
                ],
            ],
        ]);
    }

    public function test_empty_list_for_learner_with_no_mistakes(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->graphQL(self::QUERY)
            ->assertGraphQLErrorFree();

        $this->assertSame([], $response->json('data.recurringMistakes'));
    }
}
