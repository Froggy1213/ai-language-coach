<?php

namespace Tests\Feature\GraphQL;

use App\Enums\LessonCardStatus;
use App\Enums\VoiceSessionStatus;
use App\Models\LessonCard;
use App\Models\Roadmap;
use App\Models\User;
use App\Models\VoiceSession;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class RequestVoiceTokenTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    private const REQUEST_TOKEN = /** @lang GraphQL */ '
        mutation ($lessonCardId: ID!) {
            requestVoiceToken(lessonCardId: $lessonCardId) {
                id
                status
                livekitUrl
                livekitToken
                lessonCard { id }
            }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'voice.livekit.url' => 'wss://livekit.test',
            'voice.livekit.api_key' => 'test-api-key',
            'voice.livekit.api_secret' => 'test-api-secret-that-is-long-enough',
            // The fleet answers instantly in every test but the timeout one, so
            // the real five-second wait is never paid.
            'voice.agent.join_timeout_seconds' => 5.0,
        ]);

        Http::preventStrayRequests();
    }

    public function test_guests_cannot_request_a_voice_token(): void
    {
        $card = LessonCard::factory()->create(['status' => LessonCardStatus::Ready]);

        $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $card->getKey()])
            ->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_creates_a_pending_session_with_a_dispatch_for_a_ready_card(): void
    {
        $user = User::factory()->create();
        $card = $this->readyCardFor($user);
        Sanctum::actingAs($user);
        $this->fakeLiveKit(agentJoins: true);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $card->getKey()]);

        $response->assertGraphQLErrorFree();

        // The learner needs the room and a token to dial the LiveKit node, and
        // the session must be active by the time the client sees it — an agent
        // is already waiting in the room.
        $this->assertSame(VoiceSessionStatus::Active->value, $response->json('data.requestVoiceToken.status'));
        $this->assertSame('wss://livekit.test', $response->json('data.requestVoiceToken.livekitUrl'));
        $this->assertNotNull($response->json('data.requestVoiceToken.livekitToken'));

        $session = VoiceSession::query()->sole();
        $this->assertSame($user->getKey(), $session->user_id);
        $this->assertSame($card->getKey(), $session->lesson_card_id);
        $this->assertSame(VoiceSessionStatus::Active, $session->status);

        // The room is named after the session, which is how the webhook finds
        // its way back to the row.
        $this->assertSame('lesson-'.$session->getKey(), $session->room_name);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/twirp/livekit.RoomService/CreateRoom')
            && $request['name'] === $session->room_name
            && $request['emptyTimeout'] === (int) config('voice.room.empty_timeout_seconds')
            && $request['maxParticipants'] === 2);
    }

    public function test_dispatches_the_agent_with_the_lesson_as_job_metadata(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $card = $this->readyCardFor($user);
        Sanctum::actingAs($user);
        $this->fakeLiveKit(agentJoins: true);

        $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $card->getKey()])
            ->assertGraphQLErrorFree();

        $session = VoiceSession::query()->sole();

        // Explicit dispatch with metadata (plan §2): the Python worker is told
        // what to teach instead of having to ask the API.
        Http::assertSent(function ($request) use ($session, $card, $user): bool {
            if (! str_ends_with($request->url(), '/twirp/livekit.AgentDispatchService/CreateDispatch')) {
                return false;
            }

            $metadata = json_decode($request['metadata'], true);

            return $request['agentName'] === config('voice.agent.name')
                && $request['room'] === $session->room_name
                && $metadata['session_id'] === $session->getKey()
                && $metadata['grammar_point'] === $card->grammarPoint->code
                && $metadata['practice_prompt'] === $card->practice_prompt
                && $metadata['target_language'] === $user->target_language
                && $metadata['level'] === $user->current_level->value;
        });
    }

    public function test_returns_the_same_session_when_one_is_still_active(): void
    {
        $user = User::factory()->create();
        $card = $this->readyCardFor($user);
        $existing = VoiceSession::factory()->for($user)->for($card, 'lessonCard')->create([
            'status' => VoiceSessionStatus::Active,
        ]);
        Sanctum::actingAs($user);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $card->getKey()]);

        $response->assertGraphQLErrorFree();
        $this->assertSame((string) $existing->getKey(), $response->json('data.requestVoiceToken.id'));

        // The guard is the point: a double-click must not open a second room.
        $this->assertSame(1, VoiceSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_reuses_a_pending_session_instead_of_opening_a_second_room(): void
    {
        $user = User::factory()->create();
        $card = $this->readyCardFor($user);
        $existing = VoiceSession::factory()->for($user)->for($card, 'lessonCard')->create([
            'status' => VoiceSessionStatus::Pending,
        ]);
        Sanctum::actingAs($user);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $card->getKey()]);

        $response->assertGraphQLErrorFree();
        $this->assertSame((string) $existing->getKey(), $response->json('data.requestVoiceToken.id'));
        $this->assertSame(1, VoiceSession::query()->count());
    }

    public function test_a_session_that_failed_to_start_does_not_block_a_new_attempt(): void
    {
        $user = User::factory()->create();
        $card = $this->readyCardFor($user);

        // A session from an earlier attempt that never reached LiveKit. It must
        // not be mistaken for the conversation the learner is already in.
        VoiceSession::factory()->for($user)->for($card, 'lessonCard')->create([
            'status' => VoiceSessionStatus::Failed,
            'fail_reason' => 'voice_start_failed',
        ]);
        Sanctum::actingAs($user);
        $this->fakeLiveKit(agentJoins: true);

        $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $card->getKey()])
            ->assertGraphQLErrorFree();

        $this->assertSame(2, VoiceSession::query()->count());

        // The old failed row is left alone; the newest one is the live session.
        $this->assertSame(
            VoiceSessionStatus::Active,
            VoiceSession::query()->latest('id')->firstOrFail()->status,
        );
    }

    public function test_a_finished_session_does_not_block_a_new_one(): void
    {
        $user = User::factory()->create();
        $card = $this->readyCardFor($user);
        VoiceSession::factory()->for($user)->for($card, 'lessonCard')->create([
            'status' => VoiceSessionStatus::Completed,
        ]);
        Sanctum::actingAs($user);
        $this->fakeLiveKit(agentJoins: true);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $card->getKey()]);

        $response->assertGraphQLErrorFree();
        $this->assertSame(2, VoiceSession::query()->count());
    }

    public function test_rejects_a_card_that_belongs_to_another_learner(): void
    {
        $user = User::factory()->create();
        $otherCard = $this->readyCardFor(User::factory()->create());
        Sanctum::actingAs($user);

        $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $otherCard->getKey()])
            ->assertGraphQLErrorMessage('This lesson card does not exist.');

        $this->assertSame(0, VoiceSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_rejects_a_locked_card(): void
    {
        $user = User::factory()->create();
        $card = $this->readyCardFor($user, ['status' => LessonCardStatus::Locked]);
        Sanctum::actingAs($user);

        $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $card->getKey()])
            ->assertGraphQLErrorMessage('This lesson card is not ready to practise yet.');

        $this->assertSame(0, VoiceSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_reports_voice_fleet_busy_when_no_agent_joins_in_time(): void
    {
        // The poll loop would otherwise sleep for the real five-second budget.
        // Syncing the fake with Carbon is what lets the loop run out of time
        // instead of spinning forever against a clock that never moves.
        Sleep::fake(syncWithCarbon: true);
        $this->freezeTime();

        $user = User::factory()->create();
        $card = $this->readyCardFor($user);
        Sanctum::actingAs($user);
        $this->fakeLiveKit(agentJoins: false);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $card->getKey()]);

        // A typed error the frontend can act on, not a spinner that never ends.
        $response->assertGraphQLErrorMessage('No voice agent joined the room within 5 seconds.');
        $this->assertSame('VOICE_FLEET_BUSY', $response->json('errors.0.extensions.code'));

        // The room was opened and nobody came, so the session is not left
        // pending: the idempotency guard would otherwise hand this dead room
        // back on the learner's next attempt.
        $session = VoiceSession::query()->sole();
        $this->assertSame(VoiceSessionStatus::Failed, $session->status);
        $this->assertSame('voice_fleet_busy', $session->fail_reason);
    }

    public function test_reports_voice_fleet_busy_when_livekit_refuses_the_dispatch(): void
    {
        $user = User::factory()->create();
        $card = $this->readyCardFor($user);
        Sanctum::actingAs($user);

        Http::fake([
            'livekit.test/twirp/livekit.RoomService/CreateRoom' => Http::response(['sid' => 'RM_1']),
            'livekit.test/twirp/livekit.AgentDispatchService/CreateDispatch' => Http::response([], 429),
        ]);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $card->getKey()]);

        $this->assertSame('VOICE_FLEET_BUSY', $response->json('errors.0.extensions.code'));
        $this->assertSame(VoiceSessionStatus::Failed, VoiceSession::query()->sole()->status);
    }

    public function test_a_livekit_failure_does_not_leave_a_session_the_guard_will_reuse(): void
    {
        $user = User::factory()->create();
        $card = $this->readyCardFor($user);
        Sanctum::actingAs($user);

        // LiveKit answers, but the room is never created.
        Http::fake([
            'livekit.test/twirp/livekit.RoomService/CreateRoom' => Http::response(['message' => 'boom'], 500),
        ]);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $card->getKey()]);

        $response->assertGraphQLErrorMessage('Could not start voice session. Please try again later.');
        $this->assertSame('VOICE_START_FAILED', $response->json('errors.0.extensions.code'));

        // The important part: a session left `pending` here would be handed back
        // by the idempotency guard on the next attempt, together with a token for
        // a room nobody is in.
        $session = VoiceSession::query()->sole();
        $this->assertSame(VoiceSessionStatus::Failed, $session->status);
        $this->assertSame('voice_start_failed', $session->fail_reason);
    }

    public function test_reports_voice_start_failed_when_livekit_refuses_agent_dispatch(): void
    {
        $user = User::factory()->create();
        $card = $this->readyCardFor($user);
        Sanctum::actingAs($user);

        Http::fake([
            'livekit.test/twirp/livekit.RoomService/CreateRoom' => Http::response(['sid' => 'RM_1']),
            'livekit.test/twirp/livekit.AgentDispatchService/CreateDispatch' => Http::response([
                'code' => 'unauthenticated',
                'msg' => 'permissions denied',
            ], 401),
        ]);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $card->getKey()]);

        // A typed error rather than an untyped internal server error with debug stack traces.
        $response->assertGraphQLErrorMessage('Could not start voice session. Please try again later.');
        $this->assertSame('VOICE_START_FAILED', $response->json('errors.0.extensions.code'));

        $session = VoiceSession::query()->sole();
        $this->assertSame(VoiceSessionStatus::Failed, $session->status);
        $this->assertSame('voice_start_failed', $session->fail_reason);
    }

    public function test_the_issued_token_is_scoped_to_the_session_room(): void
    {
        $user = User::factory()->create();
        $card = $this->readyCardFor($user);
        Sanctum::actingAs($user);
        $this->fakeLiveKit(agentJoins: true);
        $this->freezeTime();

        $response = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $card->getKey()]);
        $session = VoiceSession::query()->sole();

        $claims = (array) JWT::decode(
            (string) $response->json('data.requestVoiceToken.livekitToken'),
            new Key('test-api-secret-that-is-long-enough', 'HS256'),
        );

        $this->assertSame('test-api-key', $claims['iss']);
        $this->assertSame('learner-'.$user->getKey(), $claims['sub']);
        $this->assertTrue($claims['video']->roomJoin);
        $this->assertSame($session->room_name, $claims['video']->room);
        $this->assertTrue($claims['video']->canPublish);

        // Plan §5 asks for a short TTL rather than the SDK's six-hour default.
        // Read off the frozen clock, which is the clock the token was minted
        // against, so the assertion states the lifetime and not just an offset.
        $this->assertSame(
            now()->addMinutes((int) config('voice.livekit.token_ttl_minutes'))->getTimestamp(),
            $claims['exp'],
        );
    }

    /**
     * A roadmap owned by the learner with one card they may practise.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function readyCardFor(User $user, array $attributes = []): LessonCard
    {
        $roadmap = Roadmap::factory()->for($user)->create();

        return LessonCard::factory()->for($roadmap)->create(
            // `+` is a union and the left operand wins, so the caller's
            // attributes have to come first for an override to take effect.
            $attributes + ['status' => LessonCardStatus::Ready],
        );
    }

    /**
     * The two calls requestVoiceToken makes, answering with or without an agent
     * in the room.
     */
    private function fakeLiveKit(bool $agentJoins): void
    {
        Http::fake([
            'livekit.test/twirp/livekit.RoomService/CreateRoom' => Http::response(['sid' => 'RM_1']),
            'livekit.test/twirp/livekit.AgentDispatchService/CreateDispatch' => Http::response(['id' => 'dispatch-1']),
            'livekit.test/twirp/livekit.RoomService/ListParticipants' => Http::response([
                'participants' => $agentJoins
                    ? [['identity' => 'agent-1', 'kind' => 'AGENT']]
                    : [['identity' => 'learner-1', 'kind' => 'STANDARD']],
            ]),
        ]);
    }
}
