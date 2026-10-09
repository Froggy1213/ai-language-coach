<?php

namespace App\Http\Controllers;

use App\Enums\VoiceSessionFailReason;
use App\Voice\VoiceSessionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
    public function __construct(private readonly VoiceSessionLifecycle $lifecycle) {}

    public function __invoke(Request $request, int $session): JsonResponse
    {
        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                Rule::in(array_map(
                    static fn (VoiceSessionFailReason $reason): string => $reason->value,
                    VoiceSessionFailReason::agentReported(),
                )),
            ],
        ]);

        $applied = $this->lifecycle->failed(
            $session,
            VoiceSessionFailReason::from($validated['reason']),
        );

        // 404 covers both "no such session" and "already finished": the agent
        // gains nothing from telling them apart, and a repeated report is not a
        // failure the caller should retry.
        return response()->json(['applied' => $applied], $applied ? 200 : 404);
    }
}
