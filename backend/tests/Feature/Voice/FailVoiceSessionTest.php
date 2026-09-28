<?php

namespace Tests\Feature\Voice;

use App\Enums\VoiceSessionStatus;
use App\Models\LessonCard;
use App\Models\Roadmap;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FailVoiceSessionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SECRET = 'internal-shared-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['voice.internal_secret' => self::SECRET]);
    }

    public function test_rejects_a_request_without_the_shared_secret(): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $this->postJson("/api/internal/sessions/{$session->getKey()}/fail", ['reason' => 'stt_failed'])
            ->assertUnauthorized();

        $this->assertSame(VoiceSessionStatus::Active, $session->fresh()->status);
    }

    public function test_rejects_a_request_with_the_wrong_shared_secret(): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $this->postJson(
            "/api/internal/sessions/{$session->getKey()}/fail",
            ['reason' => 'stt_failed'],
            ['X-Internal-Secret' => 'not-the-secret'],
        )->assertUnauthorized();

        $this->assertSame(VoiceSessionStatus::Active, $session->fresh()->status);
    }

    public function test_refuses_every_request_when_no_secret_is_configured(): void
    {
        // Failing closed matters more than the endpoint working: a deployment
        // that forgot the secret must not accept anonymous failure reports.
        config(['voice.internal_secret' => null]);

        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $this->postJson(
            "/api/internal/sessions/{$session->getKey()}/fail",
            ['reason' => 'stt_failed'],
            ['X-Internal-Secret' => ''],
        )->assertStatus(503);
    }

    public function test_marks_an_active_session_failed(): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $this->reportFailure($session->getKey(), 'stt_failed')
            ->assertOk()
            ->assertJson(['applied' => true]);

        $session->refresh();
        $this->assertSame(VoiceSessionStatus::Failed, $session->status);
        $this->assertSame('stt_failed', $session->fail_reason);
    }

    public function test_marks_a_pending_session_failed(): void
    {
        // The agent can die during its own startup, before the learner joined.
        $session = $this->sessionWithStatus(VoiceSessionStatus::Pending);

        $this->reportFailure($session->getKey(), 'agent_error')->assertOk();

        $this->assertSame(VoiceSessionStatus::Failed, $session->fresh()->status);
    }

    public function test_does_not_downgrade_a_session_that_already_finished(): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Completed);

        $this->reportFailure($session->getKey(), 'llm_failed')->assertNotFound();

        $session->refresh();
        $this->assertSame(VoiceSessionStatus::Completed, $session->status);
        $this->assertNull($session->fail_reason);
    }

    public function test_reports_a_second_failure_once_only(): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $this->reportFailure($session->getKey(), 'stt_failed')->assertOk();
        $this->reportFailure($session->getKey(), 'tts_failed')->assertNotFound();

        $this->assertSame('stt_failed', $session->fresh()->fail_reason);
    }

    public function test_rejects_a_reason_outside_the_allowlist(): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $this->reportFailure($session->getKey(), 'something_else')
            ->assertJsonValidationErrors('reason');

        $this->assertSame(VoiceSessionStatus::Active, $session->fresh()->status);
    }

    public function test_rejects_a_reason_that_is_not_a_string(): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $this->reportFailure($session->getKey(), ['nested' => 'array'])
            ->assertJsonValidationErrors('reason');
    }

    public function test_an_unknown_session_is_not_found(): void
    {
        $this->reportFailure(999999, 'stt_failed')->assertNotFound();
    }

    public function test_a_non_numeric_session_id_does_not_match_the_route(): void
    {
        $this->postJson(
            '/api/internal/sessions/not-a-number/fail',
            ['reason' => 'stt_failed'],
            ['X-Internal-Secret' => self::SECRET],
        )->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedReasons(): array
    {
        return [
            'stt_failed' => ['stt_failed'],
            'tts_failed' => ['tts_failed'],
            'llm_failed' => ['llm_failed'],
            'agent_error' => ['agent_error'],
        ];
    }

    #[DataProvider('allowedReasons')]
    public function test_accepts_each_reason_the_agent_may_report(string $reason): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $this->reportFailure($session->getKey(), $reason)->assertOk();

        $this->assertSame($reason, $session->fresh()->fail_reason);
    }

    private function reportFailure(int $sessionId, mixed $reason): TestResponse
    {
        return $this->postJson(
            "/api/internal/sessions/{$sessionId}/fail",
            ['reason' => $reason],
            ['X-Internal-Secret' => self::SECRET],
        );
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
