<?php

namespace Tests\Feature\GraphQL;

use App\Enums\VoiceSessionStatus;
use App\Models\Mistake;
use App\Models\User;
use App\Models\VoiceSession;
use Database\Seeders\GrammarPointSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class VoiceSessionQueryTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    private const QUERY = /** @lang GraphQL */ '
        query ($id: ID!) {
            voiceSession(id: $id) {
                id
                status
                mistakes {
                    id
                    userUtterance
                    correction
                    explanation
                    grammarPoint {
                        id
                        code
                    }
                }
            }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GrammarPointSeeder::class);
    }

    public function test_a_learner_may_query_their_own_voice_session_and_mistakes(): void
    {
        $user = User::factory()->create();
        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Completed,
        ]);
        $mistake = Mistake::factory()->for($session, 'session')->for($user)->create([
            'user_utterance' => 'He go home',
            'correction' => 'He goes home',
            'explanation' => 'Agreement rule',
        ]);

        Sanctum::actingAs($user);

        $response = $this->graphQL(self::QUERY, ['id' => (string) $session->id])
            ->assertGraphQLErrorFree();

        $data = $response->json('data.voiceSession');
        $this->assertNotNull($data);
        $this->assertSame((string) $session->id, $data['id']);
        $this->assertSame('completed', $data['status']);
        $this->assertCount(1, $data['mistakes']);
        $this->assertSame('He go home', $data['mistakes'][0]['userUtterance']);
        $this->assertSame('He goes home', $data['mistakes'][0]['correction']);
    }

    public function test_a_learner_cannot_query_somebody_elses_voice_session(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $session = VoiceSession::factory()->for($owner)->create();

        Sanctum::actingAs($otherUser);

        $response = $this->graphQL(self::QUERY, ['id' => (string) $session->id])
            ->assertGraphQLErrorFree();

        $this->assertNull($response->json('data.voiceSession'));
    }

    public function test_a_guest_cannot_query_voice_sessions(): void
    {
        $user = User::factory()->create();
        $session = VoiceSession::factory()->for($user)->create();

        $this->graphQL(self::QUERY, ['id' => (string) $session->id])
            ->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_token_is_null_for_terminal_sessions_and_present_for_active(): void
    {
        config([
            'voice.livekit.api_key' => 'test-key',
            'voice.livekit.api_secret' => 'test-secret-at-least-32-chars-long!',
        ]);

        $user = User::factory()->create();
        $terminalSession = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Completed,
        ]);
        $activeSession = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Active,
        ]);

        Sanctum::actingAs($user);

        $query = /** @lang GraphQL */ '
            query ($id: ID!) {
                voiceSession(id: $id) {
                    id
                    status
                    livekitToken
                }
            }
        ';

        $terminalResponse = $this->graphQL($query, ['id' => (string) $terminalSession->id])
            ->assertGraphQLErrorFree();
        $this->assertNull($terminalResponse->json('data.voiceSession.livekitToken'));

        $activeResponse = $this->graphQL($query, ['id' => (string) $activeSession->id])
            ->assertGraphQLErrorFree();
        $this->assertNotNull($activeResponse->json('data.voiceSession.livekitToken'));
    }
}
