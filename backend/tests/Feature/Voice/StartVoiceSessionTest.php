<?php

namespace Tests\Feature\Voice;

use App\Enums\LessonCardStatus;
use App\Enums\VoiceSessionStatus;
use App\Models\LessonCard;
use App\Models\Roadmap;
use App\Models\User;
use App\Models\VoiceSession;
use App\Scenarios\ScenarioCatalogue;
use App\Scenarios\ScenarioContent;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class StartVoiceSessionTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    private const REQUEST_TOKEN = /** @lang GraphQL */ '
        mutation ($lessonCardId: ID, $scenarioCode: String) {
            requestVoiceToken(lessonCardId: $lessonCardId, scenarioCode: $scenarioCode) {
                id
                status
                livekitUrl
                livekitToken
                lessonCard { id }
            }
        }
    ';

    /**
     * The metadata of the last dispatched agent job, decoded, and its exact raw
     * string (the size assertion is about the bytes the worker receives).
     */
    private ?array $dispatchMetadata = null;

    private ?string $dispatchMetadataJson = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'voice.livekit.url' => 'wss://livekit.test',
            'voice.livekit.public_url' => null,
            'voice.livekit.api_key' => 'test-api-key',
            'voice.livekit.api_secret' => 'test-api-secret-that-is-long-enough',
            'voice.agent.join_timeout_seconds' => 5.0,
        ]);

        Http::preventStrayRequests();
    }

    public function test_starts_a_scenario_session_and_dispatches_scenario_metadata(): void
    {
        $scenario = $this->scenario('free_talk');
        $user = User::factory()->create(['target_language' => 'en']);
        Sanctum::actingAs($user);
        $this->fakeLiveKit(agentJoins: true);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['scenarioCode' => 'free_talk']);

        $response->assertGraphQLErrorFree();
        $this->assertSame(VoiceSessionStatus::Active->value, $response->json('data.requestVoiceToken.status'));

        $session = VoiceSession::query()->sole();
        $this->assertSame($user->getKey(), $session->user_id);
        // Exactly one subject: no lesson card, and the code names the scenario.
        $this->assertNull($session->lesson_card_id);
        $this->assertSame('free_talk', $session->scenario_code);
        $this->assertSame(VoiceSessionStatus::Active, $session->status);
        $this->assertSame('practice-'.$session->getKey(), $session->room_name);

        $metadata = $this->dispatchMetadata();
        $this->assertSame('scenario', $metadata['kind']);
        $this->assertSame('free_talk', $metadata['scenario_code']);
        $this->assertSame($scenario->setting, $metadata['setting']);
        $this->assertSame($scenario->aiRole, $metadata['ai_role']);
        $this->assertSame($scenario->aiGoal, $metadata['ai_goal']);
        $this->assertSame($scenario->learnerRole, $metadata['learner_role']);
        $this->assertSame($scenario->learnerGoal, $metadata['learner_goal']);
        $this->assertSame($scenario->register->value, $metadata['register']);
        $this->assertSame($scenario->complications, $metadata['complications']);
        $this->assertSame($scenario->openingLine, $metadata['opening_line']);
        $this->assertSame($user->target_language, $metadata['target_language']);
        $this->assertSame($user->current_level->value, $metadata['level']);
        $this->assertSame($session->room_name, $metadata['room_name']);
        $this->assertSame($session->getKey(), $metadata['session_id']);

        // The scenario payload must not carry the lesson-only keys, and the
        // screen-only phrases are deliberately left out (plan §4.3).
        $this->assertArrayNotHasKey('grammar_point', $metadata);
        $this->assertArrayNotHasKey('grammar_point_id', $metadata);
        $this->assertArrayNotHasKey('practice_prompt', $metadata);
        $this->assertArrayNotHasKey('useful_phrases', $metadata);

        // LiveKit job metadata has a hard size ceiling; the prompt only reads a
        // handful of short fields, so the payload has to stay well under it.
        $this->assertLessThan(2048, strlen((string) $this->dispatchMetadataJson));
    }

    public function test_a_lesson_still_starts_and_dispatches_lesson_metadata(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $card = $this->readyCardFor($user);
        Sanctum::actingAs($user);
        $this->fakeLiveKit(agentJoins: true);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $card->getKey()]);

        $response->assertGraphQLErrorFree();

        $session = VoiceSession::query()->sole();
        $this->assertSame($card->getKey(), $session->lesson_card_id);
        $this->assertNull($session->scenario_code);
        $this->assertSame('practice-'.$session->getKey(), $session->room_name);
        $this->assertSame((string) $session->getKey(), $response->json('data.requestVoiceToken.id'));

        $metadata = $this->dispatchMetadata();
        $this->assertSame('lesson', $metadata['kind']);
        $this->assertSame($card->grammarPoint->code, $metadata['grammar_point']);
        $this->assertSame($card->grammar_point_id, $metadata['grammar_point_id']);
        $this->assertSame($card->practice_prompt, $metadata['practice_prompt']);
        $this->assertSame($user->target_language, $metadata['target_language']);
        $this->assertSame($user->current_level->value, $metadata['level']);
    }

    public function test_an_unknown_scenario_is_reported_as_scenario_not_found(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        Sanctum::actingAs($user);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['scenarioCode' => 'no_such_scenario']);

        $response->assertGraphQLErrorMessage('This scenario does not exist.');
        $this->assertSame('SCENARIO_NOT_FOUND', $response->json('errors.0.extensions.code'));

        // Nothing was reserved and nothing was sent to LiveKit.
        $this->assertSame(0, VoiceSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_both_arguments_are_rejected_as_an_invalid_practice_subject(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $card = $this->readyCardFor($user);
        Sanctum::actingAs($user);

        $response = $this->graphQL(self::REQUEST_TOKEN, [
            'lessonCardId' => $card->getKey(),
            'scenarioCode' => 'free_talk',
        ]);

        $this->assertSame('PRACTICE_SUBJECT_INVALID', $response->json('errors.0.extensions.code'));
        $this->assertSame(0, VoiceSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_neither_argument_is_rejected_as_an_invalid_practice_subject(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        Sanctum::actingAs($user);

        $response = $this->graphQL(self::REQUEST_TOKEN);

        $this->assertSame('PRACTICE_SUBJECT_INVALID', $response->json('errors.0.extensions.code'));
        $this->assertSame(0, VoiceSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_blank_arguments_are_treated_as_absent(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        Sanctum::actingAs($user);
        $this->fakeLiveKit(agentJoins: true);

        // An empty string for the mode the client is not in counts as omitted,
        // and the scenario code is trimmed before the catalogue is consulted.
        $response = $this->graphQL(self::REQUEST_TOKEN, [
            'lessonCardId' => '',
            'scenarioCode' => '  free_talk  ',
        ]);

        $response->assertGraphQLErrorFree();
        $this->assertSame('free_talk', VoiceSession::query()->sole()->scenario_code);

        // Both blank is still the XOR error rather than a scenario named "".
        $invalid = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => '   ', 'scenarioCode' => '']);
        $this->assertSame('PRACTICE_SUBJECT_INVALID', $invalid->json('errors.0.extensions.code'));
    }

    public function test_an_active_lesson_does_not_block_or_get_returned_for_a_scenario_start(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $card = $this->readyCardFor($user);
        $lesson = VoiceSession::factory()->for($user)->for($card, 'lessonCard')->create([
            'status' => VoiceSessionStatus::Active,
        ]);
        Sanctum::actingAs($user);
        $this->fakeLiveKit(agentJoins: true);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['scenarioCode' => 'free_talk']);

        $response->assertGraphQLErrorFree();

        // Idempotency is per subject: the live lesson is not the scenario's
        // current session, so a new scenario room is opened beside it.
        $this->assertNotSame((string) $lesson->getKey(), $response->json('data.requestVoiceToken.id'));
        $this->assertSame(2, VoiceSession::query()->count());
        $this->assertSame(VoiceSessionStatus::Active, $lesson->fresh()->status);

        $scenario = VoiceSession::query()->latest('id')->firstOrFail();
        $this->assertSame('free_talk', $scenario->scenario_code);
        $this->assertNull($scenario->lesson_card_id);
        $this->assertSame(VoiceSessionStatus::Active, $scenario->status);
    }

    public function test_an_active_scenario_does_not_block_or_get_returned_for_a_lesson_start(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $card = $this->readyCardFor($user);
        $scenarioSession = VoiceSession::factory()->for($user)->scenario('free_talk')->create([
            'status' => VoiceSessionStatus::Active,
        ]);
        Sanctum::actingAs($user);
        $this->fakeLiveKit(agentJoins: true);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $card->getKey()]);

        $response->assertGraphQLErrorFree();

        $this->assertNotSame((string) $scenarioSession->getKey(), $response->json('data.requestVoiceToken.id'));
        $this->assertSame(2, VoiceSession::query()->count());
        $this->assertSame(VoiceSessionStatus::Active, $scenarioSession->fresh()->status);

        $lesson = VoiceSession::query()->latest('id')->firstOrFail();
        $this->assertSame($card->getKey(), $lesson->lesson_card_id);
        $this->assertNull($lesson->scenario_code);
    }

    public function test_the_daily_quota_is_shared_between_lessons_and_scenarios(): void
    {
        config(['voice.daily_session_limit' => 1]);

        $user = User::factory()->create(['target_language' => 'en']);
        $card = $this->readyCardFor($user);
        Sanctum::actingAs($user);
        $this->fakeLiveKit(agentJoins: true);

        $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $card->getKey()])
            ->assertGraphQLErrorFree();
        $this->assertSame(1, $user->voiceSessions()->count());

        // The scenario has its own empty subject history, so only the shared
        // daily cap can stop it — which is exactly the point.
        $response = $this->graphQL(self::REQUEST_TOKEN, ['scenarioCode' => 'free_talk']);

        $response->assertGraphQLErrorMessage('Daily voice session limit reached (1 session per day). Please try again tomorrow.');
        $this->assertSame('VOICE_DAILY_LIMIT_REACHED', $response->json('errors.0.extensions.code'));
        $this->assertSame(1, $user->voiceSessions()->count());
        $this->assertSame(0, VoiceSession::query()->whereNull('lesson_card_id')->count());
    }

    public function test_a_webhook_for_a_practice_room_finds_the_session(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $session = VoiceSession::factory()->for($user)->scenario('free_talk')->create([
            'status' => VoiceSessionStatus::Active,
            'room_name' => 'practice-4242',
        ]);

        $body = [
            'event' => 'room_finished',
            'room' => ['name' => $session->room_name, 'creationTime' => '1000'],
            'createdAt' => '1480',
            'roomEndReason' => 'ROOM_END_API_DELETE',
        ];

        // Signed the way LiveKit signs it: a JWT over the exact body bytes.
        $token = JWT::encode([
            'iss' => 'test-api-key',
            'exp' => time() + 60,
            'sha256' => base64_encode(hash('sha256', json_encode($body), true)),
        ], 'test-api-secret-that-is-long-enough', 'HS256');

        $this->call(
            'POST',
            '/api/webhooks/livekit',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode($body),
        )->assertOk()->assertJson(['applied' => true]);

        // The webhook looks the row up by room_name and never parses the
        // prefix, which is why renaming `lesson-{id}` to `practice-{id}` is safe.
        $session->refresh();
        $this->assertSame(VoiceSessionStatus::Completed, $session->status);
        $this->assertSame(480, $session->duration_sec);
    }

    private function scenario(string $code): ScenarioContent
    {
        $scenario = app(ScenarioCatalogue::class)->find('en', $code);

        $this->assertInstanceOf(ScenarioContent::class, $scenario, "The `en.{$code}` scenario is missing from the catalogue.");

        return $scenario;
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchMetadata(): array
    {
        $this->assertIsArray($this->dispatchMetadata, 'No agent dispatch carried metadata.');

        return $this->dispatchMetadata;
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
            $attributes + ['status' => LessonCardStatus::Ready],
        );
    }

    /**
     * The LiveKit calls a start makes. The dispatch response is where the job
     * metadata is observable, so it is captured while the request is served.
     */
    private function fakeLiveKit(bool $agentJoins): void
    {
        $this->dispatchMetadata = null;
        $this->dispatchMetadataJson = null;

        Http::fake([
            'livekit.test/twirp/livekit.RoomService/CreateRoom' => Http::response(['sid' => 'RM_1']),
            'livekit.test/twirp/livekit.AgentDispatchService/CreateDispatch' => function ($request) {
                $this->dispatchMetadataJson = (string) $request['metadata'];
                $this->dispatchMetadata = json_decode($this->dispatchMetadataJson, true, flags: JSON_THROW_ON_ERROR);

                return Http::response(['id' => 'dispatch-1']);
            },
            'livekit.test/twirp/livekit.RoomService/ListParticipants' => Http::response([
                'participants' => $agentJoins
                    ? [['identity' => 'agent-1', 'kind' => 'AGENT']]
                    : [['identity' => 'learner-1', 'kind' => 'STANDARD']],
            ]),
        ]);
    }
}
