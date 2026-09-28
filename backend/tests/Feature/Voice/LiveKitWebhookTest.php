<?php

namespace Tests\Feature\Voice;

use App\Enums\VoiceSessionStatus;
use App\Models\LessonCard;
use App\Models\Roadmap;
use App\Models\User;
use App\Models\VoiceSession;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LiveKitWebhookTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SECRET = 'test-api-secret-that-is-long-enough';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'voice.livekit.url' => 'wss://livekit.test',
            'voice.livekit.api_key' => 'test-api-key',
            'voice.livekit.api_secret' => self::SECRET,
        ]);
    }

    public function test_rejects_a_request_without_a_signature(): void
    {
        $this->postJson('/api/webhooks/livekit', ['event' => 'room_finished'])
            ->assertUnauthorized();
    }

    public function test_rejects_a_signature_that_does_not_verify(): void
    {
        $body = ['event' => 'room_finished', 'room' => ['name' => 'lesson-1']];

        $this->call(
            'POST',
            '/api/webhooks/livekit',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->sign($body, 'a-different-secret-that-is-long-enough'), 'CONTENT_TYPE' => 'application/json'],
            content: json_encode($body),
        )->assertUnauthorized();
    }

    public function test_rejects_a_signature_whose_body_hash_does_not_match(): void
    {
        // Signed over one payload and delivered with another: the signature
        // verifies, the body digest does not.
        $token = $this->sign(['event' => 'room_finished', 'room' => ['name' => 'lesson-1']]);
        $tampered = ['event' => 'room_finished', 'room' => ['name' => 'lesson-99']];

        $this->call(
            'POST',
            '/api/webhooks/livekit',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode($tampered),
        )->assertUnauthorized();
    }

    public function test_rejects_a_signature_issued_for_another_project(): void
    {
        // Signed with our secret, but issued under a different API key — a
        // token minted for somebody else's LiveKit project.
        $body = ['event' => 'room_finished', 'room' => ['name' => 'lesson-1']];

        $token = JWT::encode([
            'iss' => 'another-projects-key',
            'exp' => time() + 60,
            'sha256' => base64_encode(hash('sha256', json_encode($body), true)),
        ], self::SECRET, 'HS256');

        $this->call(
            'POST',
            '/api/webhooks/livekit',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode($body),
        )->assertUnauthorized();
    }

    public function test_the_learner_joining_activates_the_session(): void
    {
        $session = $this->pendingSession();

        $this->send($this->event('participant_joined', $session->room_name, [
            'participant' => ['identity' => 'learner-'.$session->user_id, 'kind' => 'STANDARD'],
        ]))->assertOk()->assertJson(['applied' => true]);

        $this->assertSame(VoiceSessionStatus::Active, $session->fresh()->status);
    }

    public function test_the_agent_joining_does_not_activate_the_session(): void
    {
        $session = $this->pendingSession();

        // The agent arrives before the learner does; the conversation has not
        // started, so the session must not look active.
        $this->send($this->event('participant_joined', $session->room_name, [
            'participant' => ['identity' => 'agent-1', 'kind' => 'AGENT'],
        ]))->assertOk()->assertJson(['applied' => false]);

        $this->assertSame(VoiceSessionStatus::Pending, $session->fresh()->status);
    }

    public function test_room_started_leaves_the_session_pending(): void
    {
        $session = $this->pendingSession();

        $this->send($this->event('room_started', $session->room_name))
            ->assertOk()
            ->assertJson(['applied' => false]);

        $this->assertSame(VoiceSessionStatus::Pending, $session->fresh()->status);
    }

    public function test_an_api_delete_completes_the_session(): void
    {
        $session = $this->activeSession();

        $this->send($this->event('room_finished', $session->room_name, [
            'roomEndReason' => 'ROOM_END_API_DELETE',
            'room' => ['name' => $session->room_name, 'createdAt' => 1000],
            'createdAt' => 1480,
        ]))->assertOk()->assertJson(['applied' => true]);

        $session->refresh();
        $this->assertSame(VoiceSessionStatus::Completed, $session->status);
        $this->assertSame(480, $session->duration_sec);
        $this->assertNull($session->fail_reason);
    }

    public function test_an_idle_timeout_abandons_the_session(): void
    {
        $session = $this->activeSession();

        // The room sat empty past its timeout: the learner walked away.
        $this->send($this->event('room_finished', $session->room_name, [
            'roomEndReason' => 'ROOM_END_IDLE_TIMEOUT',
        ]))->assertOk()->assertJson(['applied' => true]);

        $this->assertSame(VoiceSessionStatus::Abandoned, $session->fresh()->status);
    }

    public function test_a_server_shutdown_fails_the_session_with_the_reason_preserved(): void
    {
        $session = $this->activeSession();

        $this->send($this->event('room_finished', $session->room_name, [
            'roomEndReason' => 'ROOM_END_SERVER_SHUTDOWN',
        ]))->assertOk();

        $session->refresh();
        $this->assertSame(VoiceSessionStatus::Failed, $session->status);
        $this->assertSame('livekit_server_shutdown', $session->fail_reason);
    }

    public function test_an_unknown_end_reason_is_not_recorded_as_an_abandonment(): void
    {
        $session = $this->activeSession();

        $this->send($this->event('room_finished', $session->room_name))->assertOk();

        $this->assertSame(VoiceSessionStatus::Completed, $session->fresh()->status);
    }

    public function test_a_repeated_room_finished_does_not_change_a_finished_session(): void
    {
        $session = $this->activeSession();

        $this->send($this->event('room_finished', $session->room_name, [
            'roomEndReason' => 'ROOM_END_API_DELETE',
            'room' => ['name' => $session->room_name, 'createdAt' => 1000],
            'createdAt' => 1480,
        ]))->assertOk()->assertJson(['applied' => true]);

        // A redelivery of the same event: the second one changes nothing, and
        // the session keeps the completed state the first one gave it.
        $this->send($this->event('room_finished', $session->room_name, [
            'roomEndReason' => 'ROOM_END_IDLE_TIMEOUT',
        ]))->assertOk()->assertJson(['applied' => false]);

        $session->refresh();
        $this->assertSame(VoiceSessionStatus::Completed, $session->status);
        $this->assertSame(480, $session->duration_sec);
    }

    public function test_a_webhook_for_an_unknown_room_is_accepted_without_effect(): void
    {
        // 200 rather than 404: LiveKit retries a non-2xx, and there is nothing
        // to retry for a room this service never opened.
        $this->send($this->event('room_finished', 'lesson-9999'))
            ->assertOk()
            ->assertJson(['applied' => false]);
    }

    public function test_an_event_without_a_room_is_rejected(): void
    {
        $this->send(['event' => 'room_finished'])->assertStatus(422);
    }

    public function test_an_unsigned_event_without_a_room_is_still_checked_for_its_signature_first(): void
    {
        // The signature is verified before the body is read, so an unsigned
        // request never reaches the payload checks.
        $this->postJson('/api/webhooks/livekit', ['event' => 'room_finished'])
            ->assertUnauthorized();
    }

    public function test_it_accepts_the_content_type_livekit_actually_sends(): void
    {
        // LiveKit posts with `application/webhook+json`, not `application/json`.
        // Nothing may key off that header: the endpoint authenticates by
        // signature and reads the raw body, so a strict content-type check would
        // reject every real event while every other test here kept passing.
        $session = $this->activeSession();
        $body = $this->event('room_finished', $session->room_name, [
            'roomEndReason' => 'ROOM_END_API_DELETE',
        ]);

        $this->call(
            'POST',
            '/api/webhooks/livekit',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->sign($body),
                'CONTENT_TYPE' => 'application/webhook+json',
            ],
            content: json_encode($body),
        )->assertOk()->assertJson(['applied' => true]);

        $this->assertSame(VoiceSessionStatus::Completed, $session->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(array $body): TestResponse
    {
        return $this->call(
            'POST',
            '/api/webhooks/livekit',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->sign($body),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($body),
        );
    }

    /**
     * A LiveKit webhook event, signed the way the server signs it: a JWT whose
     * `sha256` claim is a digest of the exact body bytes.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(string $name, string $roomName, array $overrides = []): array
    {
        return array_replace([
            'event' => $name,
            'room' => ['name' => $roomName, 'createdAt' => 1000],
            'createdAt' => 1500,
            'id' => 'evt-1',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function sign(array $body, string $secret = self::SECRET): string
    {
        return JWT::encode([
            'iss' => 'test-api-key',
            'exp' => time() + 60,
            'sha256' => base64_encode(hash('sha256', json_encode($body), true)),
        ], $secret, 'HS256');
    }

    private function pendingSession(): VoiceSession
    {
        return $this->sessionWithStatus(VoiceSessionStatus::Pending);
    }

    private function activeSession(): VoiceSession
    {
        return $this->sessionWithStatus(VoiceSessionStatus::Active);
    }

    private function sessionWithStatus(VoiceSessionStatus $status): VoiceSession
    {
        $user = User::factory()->create();
        $roadmap = Roadmap::factory()->for($user)->create();
        $card = LessonCard::factory()->for($roadmap)->create();

        return VoiceSession::factory()->for($user)->for($card, 'lessonCard')->create([
            'status' => $status,
        ]);
    }
}
