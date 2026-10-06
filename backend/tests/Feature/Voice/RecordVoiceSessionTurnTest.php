<?php

namespace Tests\Feature\Voice;

use App\Enums\VoiceSessionStatus;
use App\Mistakes\AnalyzeVoiceSessionMistakes;
use App\Models\LessonCard;
use App\Models\Roadmap;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RecordVoiceSessionTurnTest extends TestCase
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

        $this->postJson("/api/internal/sessions/{$session->getKey()}/turns", [
            'turn_id' => 'turn-1',
            'stt_final' => 250.0,
        ])->assertUnauthorized();

        $this->assertNull($session->fresh()->transcript);
    }

    public function test_rejects_a_request_with_the_wrong_shared_secret(): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $this->postJson(
            "/api/internal/sessions/{$session->getKey()}/turns",
            ['turn_id' => 'turn-1', 'stt_final' => 250.0],
            ['X-Internal-Secret' => 'wrong-secret'],
        )->assertUnauthorized();

        $this->assertNull($session->fresh()->transcript);
    }

    public function test_refuses_every_request_when_no_secret_is_configured(): void
    {
        config(['voice.internal_secret' => null]);

        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $this->postJson(
            "/api/internal/sessions/{$session->getKey()}/turns",
            ['turn_id' => 'turn-1', 'stt_final' => 250.0],
            ['X-Internal-Secret' => ''],
        )->assertStatus(503);

        $this->assertNull($session->fresh()->transcript);
    }

    public function test_stores_a_happy_path_turn_with_all_metrics(): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $this->recordTurn($session->getKey(), [
            'turn_id' => 'turn-1',
            'transcript' => 'I have lived here for two years.',
            'stt_final' => 245.5,
            'llm_first_token' => 380.0,
            'tts_first_chunk' => 150.2,
            'total_turnaround' => 775.7,
        ])->assertOk()->assertJson(['applied' => true]);

        $session->refresh();
        $this->assertIsArray($session->transcript);
        $this->assertCount(1, $session->transcript);
        $this->assertEquals([
            'turn_id' => 'turn-1',
            'transcript' => 'I have lived here for two years.',
            'stt_final' => 245.5,
            'llm_first_token' => 380.0,
            'tts_first_chunk' => 150.2,
            'total_turnaround' => 775.7,
        ], $session->transcript[0]);
    }

    public function test_an_identical_redelivery_does_not_duplicate_the_turn(): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $payload = [
            'turn_id' => 'turn-1',
            'transcript' => 'Hello there',
            'stt_final' => 200.0,
            'llm_first_token' => 350.0,
            'tts_first_chunk' => 120.0,
            'total_turnaround' => 670.0,
        ];

        $this->recordTurn($session->getKey(), $payload)->assertOk();
        $this->recordTurn($session->getKey(), $payload)->assertOk();

        $session->refresh();
        $this->assertCount(1, $session->transcript);
        $this->assertSame('turn-1', $session->transcript[0]['turn_id']);
    }

    public function test_a_redelivered_turn_updates_in_place(): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $this->recordTurn($session->getKey(), [
            'turn_id' => 'turn-1',
            'transcript' => 'Draft text',
            'stt_final' => 200.0,
            'llm_first_token' => null,
            'tts_first_chunk' => null,
            'total_turnaround' => null,
        ])->assertOk();

        $this->recordTurn($session->getKey(), [
            'turn_id' => 'turn-1',
            'transcript' => 'Final text',
            'stt_final' => 200.0,
            'llm_first_token' => 350.0,
            'tts_first_chunk' => 120.0,
            'total_turnaround' => 670.0,
        ])->assertOk();

        $session->refresh();
        $this->assertCount(1, $session->transcript);
        $this->assertSame('Final text', $session->transcript[0]['transcript']);
        $this->assertEquals(350.0, $session->transcript[0]['llm_first_token']);
        $this->assertEquals(670.0, $session->transcript[0]['total_turnaround']);
    }

    public function test_a_second_distinct_turn_appends_to_the_transcript(): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $this->recordTurn($session->getKey(), [
            'turn_id' => 'turn-1',
            'transcript' => 'First learner utterance',
            'stt_final' => 210.0,
            'llm_first_token' => 300.0,
            'tts_first_chunk' => 100.0,
            'total_turnaround' => 610.0,
        ])->assertOk();

        $this->recordTurn($session->getKey(), [
            'turn_id' => 'turn-2',
            'transcript' => 'Second learner utterance',
            'stt_final' => 190.0,
            'llm_first_token' => 280.0,
            'tts_first_chunk' => 110.0,
            'total_turnaround' => 580.0,
        ])->assertOk();

        $session->refresh();
        $this->assertCount(2, $session->transcript);
        $this->assertSame('turn-1', $session->transcript[0]['turn_id']);
        $this->assertSame('turn-2', $session->transcript[1]['turn_id']);
    }

    public function test_accepts_turn_on_a_terminal_session_without_changing_status(): void
    {
        Queue::fake();

        $session = $this->sessionWithStatus(VoiceSessionStatus::Completed);

        $this->recordTurn($session->getKey(), [
            'turn_id' => 'final-turn',
            'transcript' => 'Goodbye!',
            'stt_final' => 150.0,
            'llm_first_token' => 250.0,
            'tts_first_chunk' => 90.0,
            'total_turnaround' => 490.0,
        ])->assertOk();

        $session->refresh();
        $this->assertSame(VoiceSessionStatus::Completed, $session->status);
        $this->assertCount(1, $session->transcript);
        $this->assertSame('final-turn', $session->transcript[0]['turn_id']);
        Queue::assertPushed(AnalyzeVoiceSessionMistakes::class, 1);
    }

    public function test_an_unknown_session_yields_404(): void
    {
        $this->recordTurn(999999, [
            'turn_id' => 'turn-1',
            'stt_final' => 200.0,
        ])->assertNotFound();
    }

    public function test_a_non_numeric_session_id_does_not_match_the_route(): void
    {
        $this->postJson(
            '/api/internal/sessions/not-a-number/turns',
            ['turn_id' => 'turn-1'],
            ['X-Internal-Secret' => self::SECRET],
        )->assertNotFound();
    }

    public function test_rejects_negative_numbers_with_422_naming_the_field(): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $fields = ['stt_final', 'llm_first_token', 'tts_first_chunk', 'total_turnaround'];
        foreach ($fields as $field) {
            $this->recordTurn($session->getKey(), [
                'turn_id' => 'turn-1',
                $field => -5.0,
            ])->assertJsonValidationErrors($field);
        }
    }

    public function test_rejects_absurd_magnitudes_with_422_naming_the_field(): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $this->recordTurn($session->getKey(), [
            'turn_id' => 'turn-1',
            'stt_final' => 999999.0,
        ])->assertJsonValidationErrors('stt_final');

        $this->recordTurn($session->getKey(), [
            'turn_id' => 'turn-1',
            'total_turnaround' => 999999.0,
        ])->assertJsonValidationErrors('total_turnaround');
    }

    public function test_rejects_missing_or_invalid_turn_id(): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $this->recordTurn($session->getKey(), [
            'stt_final' => 200.0,
        ])->assertJsonValidationErrors('turn_id');

        $this->recordTurn($session->getKey(), [
            'turn_id' => '',
            'stt_final' => 200.0,
        ])->assertJsonValidationErrors('turn_id');

        $this->recordTurn($session->getKey(), [
            'turn_id' => ['nested' => 'array'],
        ])->assertJsonValidationErrors('turn_id');

        $this->recordTurn($session->getKey(), [
            'turn_id' => null,
            'speech_id' => null,
            'stt_final' => 200.0,
        ])->assertStatus(422)->assertJsonValidationErrors('turn_id');
    }

    public function test_accepts_speech_id_as_alias_for_turn_id(): void
    {
        $session = $this->sessionWithStatus(VoiceSessionStatus::Active);

        $this->recordTurn($session->getKey(), [
            'speech_id' => 'speech-xyz-123',
            'stt_final' => 180.0,
        ])->assertOk();

        $session->refresh();
        $this->assertCount(1, $session->transcript);
        $this->assertSame('speech-xyz-123', $session->transcript[0]['turn_id']);
    }

    private function recordTurn(int $sessionId, array $payload): TestResponse
    {
        return $this->postJson(
            "/api/internal/sessions/{$sessionId}/turns",
            $payload,
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
