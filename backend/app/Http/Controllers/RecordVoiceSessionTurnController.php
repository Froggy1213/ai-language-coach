<?php

namespace App\Http\Controllers;

use App\Observability\PublishTurnMetrics;
use App\Voice\VoiceSessionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Records per-turn latency instrumentation from the voice agent (plan §5).
 *
 * Guarded by VerifyInternalSecret (X-Internal-Secret header).
 * Latency metrics (stt_final, llm_first_token, tts_first_chunk, total_turnaround)
 * are stored in milliseconds (ms).
 */
final class RecordVoiceSessionTurnController extends Controller
{
    public function __construct(private readonly VoiceSessionLifecycle $lifecycle) {}

    public function __invoke(Request $request, int $session): JsonResponse
    {
        $validated = $request->validate([
            'turn_id' => ['required_without:speech_id', 'nullable', 'string', 'min:1', 'max:255'],
            'speech_id' => ['required_without:turn_id', 'nullable', 'string', 'min:1', 'max:255'],
            'transcript' => ['nullable', 'string', 'max:65535'],
            'stt_final' => ['nullable', 'numeric', 'min:0', 'max:60000'],
            'llm_first_token' => ['nullable', 'numeric', 'min:0', 'max:60000'],
            'tts_first_chunk' => ['nullable', 'numeric', 'min:0', 'max:60000'],
            'total_turnaround' => ['nullable', 'numeric', 'min:0', 'max:120000'],
        ]);

        $turnId = (string) ($validated['turn_id'] ?? $validated['speech_id'] ?? '');

        if ($turnId === '') {
            throw ValidationException::withMessages([
                'turn_id' => 'The turn identifier must not be empty.',
            ]);
        }

        $turn = [
            'turn_id' => $turnId,
            'transcript' => $validated['transcript'] ?? null,
        ];

        foreach (PublishTurnMetrics::STAGES as $stage) {
            $turn[$stage] = array_key_exists($stage, $validated) && $validated[$stage] !== null
                ? (float) $validated[$stage]
                : null;
        }

        $applied = $this->lifecycle->recordTurn($session, $turn);

        return response()->json(['applied' => $applied], $applied ? 200 : 404);
    }
}
