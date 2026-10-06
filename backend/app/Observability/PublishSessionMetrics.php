<?php

namespace App\Observability;

use App\Enums\VoiceSessionStatus;
use App\Models\VoiceSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Publishes what a finished voice session cost (plan §7).
 *
 * The session row is the only place that knows both the outcome and how many
 * seconds of vendor STT/TTS/LLM the learner actually consumed, which is what the
 * account's AWS Budget alert is checked against. The row is re-read here rather
 * than serialised into the payload so a late delivery cannot publish a session
 * that has since been corrected by a redelivered webhook.
 */
final class PublishSessionMetrics implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public readonly int $sessionId) {}

    public function handle(MetricPublisher $publisher): void
    {
        $session = VoiceSession::query()
            ->with('user:id,target_language')
            ->find($this->sessionId);

        if (! $session instanceof VoiceSession) {
            return;
        }

        $outcome = [
            'Status' => $session->status->value,
            // `none` rather than an absent dimension, so one dashboard widget can
            // group by reason without a separate series for the happy path.
            'Reason' => filled($session->fail_reason) ? (string) $session->fail_reason : 'none',
        ];

        $data = [new MetricDatum('SessionOutcome', 1.0, 'Count', $outcome)];

        if (($session->duration_sec ?? 0) > 0) {
            $data[] = new MetricDatum(
                name: 'VoiceSeconds',
                value: (float) $session->duration_sec,
                unit: 'Seconds',
                dimensions: ['Language' => (string) ($session->user?->target_language ?? 'unknown')],
            );
        }

        $publisher->putMany($data);
    }

    /**
     * A session is only worth reporting once it has stopped moving.
     */
    public static function isPublishable(VoiceSessionStatus $status): bool
    {
        return $status->isTerminal();
    }
}
