<?php

namespace Tests\Feature\Observability;

use App\Enums\VoiceSessionStatus;
use App\Models\User;
use App\Models\VoiceSession;
use App\Observability\MetricDatum;
use App\Observability\MetricPublisher;
use App\Observability\PublishSessionMetrics;
use App\Voice\VoiceSessionLifecycle;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublishSessionMetricsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_finished_session_queues_its_outcome_and_its_minutes(): void
    {
        Queue::fake();

        $session = VoiceSession::factory()->for(User::factory())->create([
            'status' => VoiceSessionStatus::Active,
        ]);

        $applied = (new VoiceSessionLifecycle)->finished($session->room_name, 'ROOM_END_API_DELETE', 480);

        $this->assertTrue($applied);

        Queue::assertPushed(
            PublishSessionMetrics::class,
            static fn (PublishSessionMetrics $job): bool => $job->sessionId === $session->id
        );
    }

    public function test_the_job_publishes_the_outcome_with_its_status_and_reason(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Failed,
            'fail_reason' => 'llm_failed',
            'duration_sec' => 300,
        ]);

        $publisher = $this->recordingPublisher();

        (new PublishSessionMetrics($session->id))->handle($publisher);

        $outcome = $publisher->published[0];
        $this->assertSame('SessionOutcome', $outcome->name);
        $this->assertSame(1.0, $outcome->value);
        $this->assertSame('Count', $outcome->unit);
        $this->assertSame(['Status' => 'failed', 'Reason' => 'llm_failed'], $outcome->dimensions);

        $seconds = $publisher->published[1];
        $this->assertSame('VoiceSeconds', $seconds->name);
        $this->assertSame(300.0, $seconds->value);
        $this->assertSame('Seconds', $seconds->unit);
        $this->assertSame(['Language' => 'en'], $seconds->dimensions);
    }

    public function test_the_happy_path_carries_no_reason_rather_than_no_dimension(): void
    {
        $session = VoiceSession::factory()->for(User::factory())->create([
            'status' => VoiceSessionStatus::Completed,
            'duration_sec' => 120,
        ]);

        $publisher = $this->recordingPublisher();

        (new PublishSessionMetrics($session->id))->handle($publisher);

        $this->assertSame(
            ['Status' => 'completed', 'Reason' => 'none'],
            $publisher->published[0]->dimensions,
        );
    }

    public function test_a_session_without_a_duration_only_reports_its_outcome(): void
    {
        $session = VoiceSession::factory()->for(User::factory())->create([
            'status' => VoiceSessionStatus::Abandoned,
            'duration_sec' => null,
        ]);

        $publisher = $this->recordingPublisher();

        (new PublishSessionMetrics($session->id))->handle($publisher);

        $this->assertCount(1, $publisher->published);
        $this->assertSame('SessionOutcome', $publisher->published[0]->name);
    }

    public function test_a_session_that_no_longer_exists_publishes_nothing(): void
    {
        $publisher = $this->recordingPublisher();

        (new PublishSessionMetrics(999999))->handle($publisher);

        $this->assertSame([], $publisher->published);
    }

    private function recordingPublisher(): MetricPublisher
    {
        return new class implements MetricPublisher
        {
            /** @var list<MetricDatum> */
            public array $published = [];

            public function putMany(array $data): void
            {
                $this->published = array_merge($this->published, $data);
            }
        };
    }
}
