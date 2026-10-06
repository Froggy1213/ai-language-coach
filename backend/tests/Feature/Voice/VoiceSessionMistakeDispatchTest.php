<?php

namespace Tests\Feature\Voice;

use App\Enums\VoiceSessionStatus;
use App\Mistakes\AnalyzeVoiceSessionMistakes;
use App\Models\VoiceSession;
use App\Voice\VoiceSessionLifecycle;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VoiceSessionMistakeDispatchTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_dispatches_mistake_analysis_when_a_session_finishes_with_learner_utterances(): void
    {
        Queue::fake();

        $session = VoiceSession::factory()->create([
            'status' => VoiceSessionStatus::Active,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'I go to school yesterday.'],
            ],
        ]);

        $applied = (new VoiceSessionLifecycle)->finished($session->room_name, 'ROOM_END_API_DELETE');

        $this->assertTrue($applied);
        $this->assertSame(VoiceSessionStatus::Completed, $session->refresh()->status);

        Queue::assertPushed(AnalyzeVoiceSessionMistakes::class, function (AnalyzeVoiceSessionMistakes $job) use ($session): bool {
            return $job->session->id === $session->id;
        });
        Queue::assertPushed(AnalyzeVoiceSessionMistakes::class, 1);
    }

    public function test_it_dispatches_mistake_analysis_when_an_abandoned_session_has_utterances(): void
    {
        Queue::fake();

        $session = VoiceSession::factory()->create([
            'status' => VoiceSessionStatus::Active,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'Hello there.'],
            ],
        ]);

        $applied = (new VoiceSessionLifecycle)->finished($session->room_name, 'ROOM_END_IDLE_TIMEOUT');

        $this->assertTrue($applied);
        $this->assertSame(VoiceSessionStatus::Abandoned, $session->refresh()->status);

        Queue::assertPushed(AnalyzeVoiceSessionMistakes::class, 1);
    }

    public function test_it_dispatches_mistake_analysis_when_a_failed_session_has_utterances(): void
    {
        Queue::fake();

        $session = VoiceSession::factory()->create([
            'status' => VoiceSessionStatus::Active,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'Hello before failure.'],
            ],
        ]);

        $applied = (new VoiceSessionLifecycle)->failed($session->id, 'llm_failed');

        $this->assertTrue($applied);
        $this->assertSame(VoiceSessionStatus::Failed, $session->refresh()->status);

        Queue::assertPushed(AnalyzeVoiceSessionMistakes::class, 1);
    }

    public function test_it_never_dispatches_when_a_session_has_nothing_to_analyse(): void
    {
        Queue::fake();

        $session = VoiceSession::factory()->create([
            'status' => VoiceSessionStatus::Pending,
            'transcript' => null,
        ]);

        $applied = (new VoiceSessionLifecycle)->finished($session->room_name, 'ROOM_END_IDLE_TIMEOUT');

        $this->assertTrue($applied);
        $this->assertSame(VoiceSessionStatus::Abandoned, $session->refresh()->status);

        Queue::assertNothingPushed();
    }

    public function test_it_never_dispatches_when_transcript_has_only_empty_utterances(): void
    {
        Queue::fake();

        $session = VoiceSession::factory()->create([
            'status' => VoiceSessionStatus::Active,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => ''],
                ['turn_id' => 't2', 'transcript' => '   '],
                ['turn_id' => 't3', 'transcript' => null],
            ],
        ]);

        (new VoiceSessionLifecycle)->finished($session->room_name, 'ROOM_END_API_DELETE');

        Queue::assertNothingPushed();
    }

    public function test_it_never_dispatches_on_non_terminal_transitions(): void
    {
        Queue::fake();

        $session = VoiceSession::factory()->create([
            'status' => VoiceSessionStatus::Pending,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'Hello'],
            ],
        ]);

        $applied = (new VoiceSessionLifecycle)->participantJoined($session->room_name);

        $this->assertTrue($applied);
        $this->assertSame(VoiceSessionStatus::Active, $session->refresh()->status);

        Queue::assertNothingPushed();
    }

    public function test_it_dispatches_exactly_once_on_duplicate_terminal_events(): void
    {
        Queue::fake();

        $session = VoiceSession::factory()->create([
            'status' => VoiceSessionStatus::Active,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'Some utterance.'],
            ],
        ]);

        $lifecycle = new VoiceSessionLifecycle;

        $first = $lifecycle->finished($session->room_name, 'ROOM_END_API_DELETE');
        $second = $lifecycle->finished($session->room_name, 'ROOM_END_API_DELETE');

        $this->assertTrue($first);
        $this->assertFalse($second);

        Queue::assertPushed(AnalyzeVoiceSessionMistakes::class, 1);
    }

    public function test_it_dispatches_when_late_turn_arrives_on_already_terminal_session(): void
    {
        Queue::fake();

        $session = VoiceSession::factory()->create([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => [],
        ]);

        $applied = (new VoiceSessionLifecycle)->recordTurn($session->id, [
            'turn_id' => 'late-turn',
            'transcript' => 'A late learner utterance.',
        ]);

        $this->assertTrue($applied);
        Queue::assertPushed(AnalyzeVoiceSessionMistakes::class, 1);
    }

    public function test_it_does_not_dispatch_when_late_turn_arrives_on_already_analyzed_session(): void
    {
        Queue::fake();

        $session = VoiceSession::factory()->create([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => [
                ['type' => 'analysis', 'mistakes_count' => 0],
            ],
        ]);

        $applied = (new VoiceSessionLifecycle)->recordTurn($session->id, [
            'turn_id' => 'late-turn-2',
            'transcript' => 'Another late utterance.',
        ]);

        $this->assertTrue($applied);
        Queue::assertNothingPushed();
    }

    public function test_failed_returns_false_when_session_id_does_not_exist(): void
    {
        $applied = (new VoiceSessionLifecycle)->failed(999999, 'error_reason');
        $this->assertFalse($applied);
    }
}
