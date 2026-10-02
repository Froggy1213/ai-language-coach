<?php

namespace App\Voice;

use App\Enums\VoiceSessionStatus;
use App\Mistakes\AnalyzeVoiceSessionMistakes;
use App\Models\VoiceSession;
use Illuminate\Support\Facades\DB;

/**
 * Moves a voice session between states exactly once (plan §5).
 *
 * Every transition is written as a conditional UPDATE guarded by the status it
 * expects to find, and the transitions are only applied from the states that
 * legitimately precede them. That is what makes the endpoints idempotent: a
 * retried `room_finished` matches no rows, changes nothing, and leaves a
 * completed session completed instead of resurrecting it as abandoned.
 *
 * Reading the row under `lockForUpdate` and then writing inside the same
 * transaction keeps two deliveries of the same event from both deciding the
 * transition applies.
 */
final class VoiceSessionLifecycle
{
    /**
     * The learner or the agent actually entered the room, so the conversation
     * is live and the session is no longer merely reserved.
     */
    public function participantJoined(string $roomName): bool
    {
        return $this->transition(
            $roomName,
            from: [VoiceSessionStatus::Pending],
            to: VoiceSessionStatus::Active,
        );
    }

    /**
     * The room closed. Why it closed is what separates the two terminal states
     * plan §3 distinguishes:
     *
     * - `ROOM_END_API_DELETE` — the agent finished its scenario and closed the
     *   room itself, so the lesson ran to the end: `completed`.
     * - `ROOM_END_IDLE_TIMEOUT` — the room sat empty past its timeout, which
     *   means the learner walked away: `abandoned`.
     * - `ROOM_END_SERVER_SHUTDOWN` / `ROOM_END_OPEN_FAILED` — infrastructure,
     *   not the learner: `failed`, with the reason preserved.
     *
     * An unrecognised or absent reason is treated as `completed`: a scenario
     * that reached its end must not be recorded as an abandonment.
     */
    public function finished(string $roomName, ?string $reason, ?int $durationSeconds = null): bool
    {
        [$status, $failReason] = match ($reason) {
            'ROOM_END_IDLE_TIMEOUT' => [VoiceSessionStatus::Abandoned, null],
            'ROOM_END_SERVER_SHUTDOWN' => [VoiceSessionStatus::Failed, 'livekit_server_shutdown'],
            'ROOM_END_OPEN_FAILED' => [VoiceSessionStatus::Failed, 'livekit_room_open_failed'],
            default => [VoiceSessionStatus::Completed, null],
        };

        return $this->transition(
            $roomName,
            from: [VoiceSessionStatus::Pending, VoiceSessionStatus::Active],
            to: $status,
            attributes: array_filter([
                'fail_reason' => $failReason,
                'duration_sec' => $durationSeconds,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * The agent hit an STT/TTS/LLM failure and said so itself before
     * disconnecting (plan §5). LiveKit never sees this — a broken provider
     * stream is not a room event — which is why it has its own endpoint.
     *
     * Only a session that is still in flight can fail: a session already marked
     * completed by a webhook that arrived first must not be downgraded.
     */
    public function failed(int $sessionId, string $reason): bool
    {
        $session = VoiceSession::query()->find($sessionId);

        if (! $session instanceof VoiceSession) {
            return false;
        }

        return $this->transition(
            $session->room_name,
            from: [VoiceSessionStatus::Pending, VoiceSessionStatus::Active],
            to: VoiceSessionStatus::Failed,
            attributes: ['fail_reason' => $reason],
        );
    }

    /**
     * Records latency metrics and transcript for a single conversational turn (plan §5).
     *
     * Idempotent: redelivered turns with the same turn_id update the turn in place
     * rather than appending a duplicate.
     *
     * Terminal sessions (completed, abandoned, failed) accept turns so in-flight
     * turn telemetry is not dropped if room closure races ahead of the agent's HTTP report.
     *
     * @param  array{turn_id: string, transcript: ?string, stt_final: ?float, llm_first_token: ?float, tts_first_chunk: ?float, total_turnaround: ?float}  $turn
     * @return bool whether the session exists and the turn was recorded
     */
    public function recordTurn(int $sessionId, array $turn): bool
    {
        return DB::transaction(function () use ($sessionId, $turn): bool {
            $session = VoiceSession::query()
                ->where('id', $sessionId)
                ->lockForUpdate()
                ->first();

            if (! $session instanceof VoiceSession) {
                return false;
            }

            $turnId = (string) ($turn['turn_id'] ?? $turn['speech_id'] ?? '');
            $transcript = $session->transcript ?? [];
            if (! is_array($transcript)) {
                $transcript = [];
            }

            $updated = false;
            foreach ($transcript as $index => $item) {
                if (is_array($item) && (($item['turn_id'] ?? null) === $turnId || ($item['speech_id'] ?? null) === $turnId)) {
                    $transcript[$index] = array_merge($item, array_filter($turn, static fn (mixed $value): bool => $value !== null));
                    $updated = true;
                    break;
                }
            }

            if (! $updated) {
                $transcript[] = $turn;
            }

            $session->transcript = array_values($transcript);
            $session->save();

            return true;
        });
    }

    /**
     * @param  list<VoiceSessionStatus>  $from
     * @param  array<string, mixed>  $attributes
     * @return bool whether this delivery is the one that applied the transition
     */
    private function transition(string $roomName, array $from, VoiceSessionStatus $to, array $attributes = []): bool
    {
        return DB::transaction(function () use ($roomName, $from, $to, $attributes): bool {
            $session = VoiceSession::query()
                ->where('room_name', $roomName)
                ->lockForUpdate()
                ->first();

            if (! $session instanceof VoiceSession || ! in_array($session->status, $from, true)) {
                return false;
            }

            $session->fill($attributes);
            $session->status = $to;
            $session->save();

            if ($to->isTerminal() && $this->hasLearnerUtterances($session)) {
                AnalyzeVoiceSessionMistakes::dispatch($session)->afterCommit();
            }

            return true;
        });
    }

    private function hasLearnerUtterances(VoiceSession $session): bool
    {
        $transcript = $session->transcript;
        if (! is_array($transcript) || empty($transcript)) {
            return false;
        }

        foreach ($transcript as $turn) {
            if (! is_array($turn) || ($turn['type'] ?? null) === 'analysis') {
                continue;
            }

            $text = trim((string) ($turn['transcript'] ?? ''));
            if ($text !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The room's lifetime from LiveKit, used when the event does not carry an
     * end timestamp of its own.
     */
    public static function durationSeconds(?int $roomCreatedAt, ?int $roomFinishedAt): ?int
    {
        if ($roomCreatedAt === null || $roomFinishedAt === null || $roomFinishedAt < $roomCreatedAt) {
            return null;
        }

        return $roomFinishedAt - $roomCreatedAt;
    }
}
