<?php

namespace Tests\Feature\Voice;

use App\Enums\VoiceSessionStatus;
use App\Mistakes\AnalyzeVoiceSessionMistakes;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class FinishVoiceSessionTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    private const FINISH = /** @lang GraphQL */ '
        mutation ($id: ID!) {
            finishVoiceSession(id: $id) {
                id
                status
                durationSec
            }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'voice.livekit.url' => 'wss://livekit.test',
            // Nulled explicitly so a developer's own LIVEKIT_PUBLIC_URL in .env
            // cannot change what these tests assert.
            'voice.livekit.public_url' => null,
            'voice.livekit.api_key' => 'test-api-key',
            'voice.livekit.api_secret' => 'test-api-secret-that-is-long-enough',
            'voice.livekit.api_timeout_seconds' => 5,
        ]);

        Http::preventStrayRequests();
    }

    public function test_guests_cannot_finish_a_voice_session(): void
    {
        $session = VoiceSession::factory()->create(['status' => VoiceSessionStatus::Active]);

        $this->graphQL(self::FINISH, ['id' => (string) $session->getKey()])
            ->assertGraphQLErrorMessage('Unauthenticated.');

        $this->assertSame(VoiceSessionStatus::Active, $session->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_an_unknown_session_is_not_found(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->graphQL(self::FINISH, ['id' => '999999']);

        $response->assertGraphQLErrorMessage('Voice session not found.');
        $this->assertSame('VOICE_SESSION_NOT_FOUND', $response->json('errors.0.extensions.code'));
        Http::assertNothingSent();
    }

    public function test_another_learners_session_is_reported_as_not_found(): void
    {
        $foreignSession = VoiceSession::factory()->create(['status' => VoiceSessionStatus::Active]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->graphQL(self::FINISH, ['id' => (string) $foreignSession->getKey()]);

        $response->assertGraphQLErrorMessage('Voice session not found.');
        $this->assertSame('VOICE_SESSION_NOT_FOUND', $response->json('errors.0.extensions.code'));
        $this->assertSame(VoiceSessionStatus::Active, $foreignSession->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_finishing_an_active_session_completes_it_and_deletes_the_room(): void
    {
        Queue::fake();
        $this->freezeTime();

        $user = User::factory()->create();
        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Active,
            'created_at' => now()->subSeconds(90),
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'I go to school yesterday.'],
            ],
        ]);
        Sanctum::actingAs($user);
        $this->fakeDeleteRoom();

        $response = $this->graphQL(self::FINISH, ['id' => (string) $session->getKey()]);

        $response->assertGraphQLErrorFree();

        // The learner finished the scenario, so the row must say completed now
        // rather than waiting for the room to idle-time-out as abandoned.
        $this->assertSame(VoiceSessionStatus::Completed->value, $response->json('data.finishVoiceSession.status'));
        $this->assertSame(90, $response->json('data.finishVoiceSession.durationSec'));

        $session->refresh();
        $this->assertSame(VoiceSessionStatus::Completed, $session->status);
        $this->assertSame(90, $session->duration_sec);

        // Deleting the room is what makes room_finished arrive immediately
        // instead of after the empty timeout, and it evicts the agent.
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/twirp/livekit.RoomService/DeleteRoom')
            && $request['room'] === $session->room_name);

        // A completed session with learner speech is worth analysing.
        Queue::assertPushed(
            AnalyzeVoiceSessionMistakes::class,
            fn (AnalyzeVoiceSessionMistakes $job): bool => $job->session->is($session),
        );
    }

    public function test_finishing_twice_is_idempotent_and_does_not_call_livekit_again(): void
    {
        Queue::fake();
        $this->freezeTime();

        $user = User::factory()->create();
        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Active,
            'created_at' => now()->subSeconds(30),
        ]);
        Sanctum::actingAs($user);
        $this->fakeDeleteRoom();

        $this->graphQL(self::FINISH, ['id' => (string) $session->getKey()])
            ->assertGraphQLErrorFree();

        // A double click or a retried response: the outcome must not change and
        // the room must not be deleted a second time.
        $second = $this->graphQL(self::FINISH, ['id' => (string) $session->getKey()]);

        $second->assertGraphQLErrorFree();
        $this->assertSame(VoiceSessionStatus::Completed->value, $second->json('data.finishVoiceSession.status'));
        $this->assertSame(30, $second->json('data.finishVoiceSession.durationSec'));
        $this->assertSame(VoiceSessionStatus::Completed, $session->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_an_already_terminal_session_is_returned_unchanged(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Abandoned,
            'duration_sec' => 42,
        ]);
        Sanctum::actingAs($user);

        $response = $this->graphQL(self::FINISH, ['id' => (string) $session->getKey()]);

        $response->assertGraphQLErrorFree();
        $this->assertSame(VoiceSessionStatus::Abandoned->value, $response->json('data.finishVoiceSession.status'));
        $this->assertSame(42, $response->json('data.finishVoiceSession.durationSec'));

        // No transition, no analysis, no LiveKit call.
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_a_livekit_failure_does_not_fail_the_mutation(): void
    {
        Queue::fake();
        $this->freezeTime();

        $user = User::factory()->create();
        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Active,
            'created_at' => now()->subSeconds(12),
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'This is my practice sentence.'],
            ],
        ]);
        Sanctum::actingAs($user);
        $this->fakeDeleteRoom(status: 500);

        $response = $this->graphQL(self::FINISH, ['id' => (string) $session->getKey()]);

        // The session state is already correct, so LiveKit being down is not an
        // error the learner should see; its empty timeout closes the room.
        $response->assertGraphQLErrorFree();
        $this->assertSame(VoiceSessionStatus::Completed->value, $response->json('data.finishVoiceSession.status'));
        $this->assertSame(VoiceSessionStatus::Completed, $session->fresh()->status);

        Queue::assertPushed(AnalyzeVoiceSessionMistakes::class, 1);
    }

    private function fakeDeleteRoom(int $status = 200): void
    {
        Http::fake([
            'livekit.test/twirp/livekit.RoomService/DeleteRoom' => Http::response([], $status),
        ]);
    }
}
