<?php

namespace Tests\Feature\Observability;

use App\Enums\VoiceSessionStatus;
use App\Models\User;
use App\Models\VoiceSession;
use App\Observability\MetricDatum;
use App\Observability\MetricPublisher;
use App\Observability\PublishTurnMetrics;
use App\Voice\VoiceSessionLifecycle;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublishTurnMetricsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_job_publishes_every_stage_of_a_turn_as_one_series(): void
    {
        $publisher = $this->recordingPublisher();

        (new PublishTurnMetrics(7, [
            'stt_final' => 300.0,
            'llm_first_token' => 812.5,
            'total_turnaround' => 1200.0,
        ]))->handle($publisher);

        $this->assertCount(3, $publisher->published);
        $this->assertSame(['stt_final', 'llm_first_token', 'total_turnaround'], array_map(
            static fn (MetricDatum $datum): string => $datum->dimensions['Stage'],
            $publisher->published,
        ));

        foreach ($publisher->published as $datum) {
            $this->assertSame('TurnLatencyMs', $datum->name);
            $this->assertSame('Milliseconds', $datum->unit);
        }
    }

    public function test_a_recorded_turn_queues_the_metrics_of_the_stages_it_actually_reported(): void
    {
        Queue::fake();

        $session = VoiceSession::factory()->for(User::factory())->create([
            'status' => VoiceSessionStatus::Active,
            'transcript' => [],
        ]);

        $applied = (new VoiceSessionLifecycle)->recordTurn($session->id, [
            'turn_id' => 'turn-1',
            'transcript' => 'Yesterday I have visited my friend.',
            'stt_final' => 320.0,
            'llm_first_token' => 780.0,
            'tts_first_chunk' => null,
            'total_turnaround' => 1180.0,
        ]);

        $this->assertTrue($applied);

        Queue::assertPushed(PublishTurnMetrics::class, function (PublishTurnMetrics $job) use ($session): bool {
            return $job->sessionId === $session->id
                && $job->stages === [
                    'stt_final' => 320.0,
                    'llm_first_token' => 780.0,
                    'total_turnaround' => 1180.0,
                ];
        });
    }

    public function test_a_turn_without_timings_queues_nothing(): void
    {
        Queue::fake();

        $session = VoiceSession::factory()->for(User::factory())->create([
            'status' => VoiceSessionStatus::Active,
            'transcript' => [],
        ]);

        (new VoiceSessionLifecycle)->recordTurn($session->id, [
            'turn_id' => 'turn-2',
            'transcript' => 'A turn the agent reported without instrumenting it.',
        ]);

        Queue::assertNotPushed(PublishTurnMetrics::class);
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
