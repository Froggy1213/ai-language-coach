<?php

namespace App\Http\Controllers;

use App\Voice\VoiceSessionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $turn = [
            'turn_id' => $turnId,
            'transcript' => $validated['transcript'] ?? null,
            'stt_final' => array_key_exists('stt_final', $validated) && $validated['stt_final'] !== null ? (float) $validated['stt_final'] : null,
            'llm_first_token' => array_key_exists('llm_first_token', $validated) && $validated['llm_first_token'] !== null ? (float) $validated['llm_first_token'] : null,
            'tts_first_chunk' => array_key_exists('tts_first_chunk', $validated) && $validated['tts_first_chunk'] !== null ? (float) $validated['tts_first_chunk'] : null,
            'total_turnaround' => array_key_exists('total_turnaround', $validated) && $validated['total_turnaround'] !== null ? (float) $validated['total_turnaround'] : null,
        ];

        $applied = $this->lifecycle->recordTurn($session, $turn);

        return response()->json(['applied' => $applied], $applied ? 200 : 404);
    }
}
