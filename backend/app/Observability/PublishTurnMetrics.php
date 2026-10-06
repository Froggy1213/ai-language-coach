<?php

namespace App\Observability;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Publishes one recorded turn's latency stages to the metrics sink (plan §7).
 *
 * Queued rather than called inline: this runs on the agent's report path, and a
 * metrics endpoint that is down must not make the worker wait — or worse, time
 * out — while it is flushing the last turns of a call.
 */
final class PublishTurnMetrics implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * The stage keys the agent reports (decision 32), in the order a learner
     * waits through them. Kept as the single list both the publisher and the
     * lifecycle agree on.
     *
     * @var list<string>
     */
    public const STAGES = ['stt_final', 'llm_first_token', 'tts_first_chunk', 'total_turnaround'];

    /**
     * @param  int  $sessionId  carried for the Horizon payload view; never a metric dimension, because a
     *                          dimension per session would cost more than the metrics are worth
     * @param  array<string, float>  $stages  milliseconds, keyed by stage
     */
    public function __construct(
        public readonly int $sessionId,
        public readonly array $stages,
    ) {}

    public function handle(MetricPublisher $publisher): void
    {
        $data = [];

        foreach ($this->stages as $stage => $milliseconds) {
            $data[] = new MetricDatum(
                name: 'TurnLatencyMs',
                value: $milliseconds,
                unit: 'Milliseconds',
                dimensions: ['Stage' => $stage],
            );
        }

        $publisher->putMany($data);
    }
}
