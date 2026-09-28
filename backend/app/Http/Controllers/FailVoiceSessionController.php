<?php

namespace App\Http\Controllers;

use App\Voice\VoiceSessionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The voice agent's failure report (plan §5 — `POST /internal/sessions/{id}/fail`).
 *
 * A session that dies because Deepgram, Cartesia or the dialogue LLM broke is
 * not a room event, so LiveKit never tells us about it. Without this endpoint
 * such a session would sit in `active` until its room idle-timed-out, and the
 * learner's history would record an abandonment that never happened.
 */
final class FailVoiceSessionController extends Controller
{
    /**
     * The reasons the agent is allowed to report. An allowlist rather than free
     * text: these values land in `voice_sessions.fail_reason`, which is read
     * back on the history screen, and an arbitrary string from another service
     * does not belong there.
     */
    private const REASONS = ['stt_failed', 'tts_failed', 'llm_failed', 'agent_error'];

    public function __construct(private readonly VoiceSessionLifecycle $lifecycle) {}

    public function __invoke(Request $request, int $session): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'in:'.implode(',', self::REASONS)],
        ]);

        $applied = $this->lifecycle->failed($session, $validated['reason']);

        // 404 covers both "no such session" and "already finished": the agent
        // gains nothing from telling them apart, and a repeated report is not a
        // failure the caller should retry.
        return response()->json(['applied' => $applied], $applied ? 200 : 404);
    }
}
