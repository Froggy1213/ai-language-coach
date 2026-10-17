<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Receives client-side WebRTC / media device failure reports from the browser.
 *
 * When a learner's browser cannot complete the ICE/DTLS handshake (for example,
 * `could not establish pc connection` behind a restrictive firewall) or cannot
 * open the microphone, neither LiveKit nor the voice agent receives a failure
 * code — the room simply sits idle. Logging the browser-side failure here gives
 * operators immediate visibility into client connectivity issues without
 * storing or shipping any learner speech.
 */
final class ReportVoiceClientErrorController extends Controller
{
    public const array STAGES = [
        'webrtc_connect',
        'microphone',
        'media_devices',
    ];

    public function __invoke(Request $request, int $session): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $validated = $request->validate([
            'stage' => ['required', 'string', Rule::in(self::STAGES)],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $voiceSession = VoiceSession::query()
            ->where('id', $session)
            ->where('user_id', $user->getKey())
            ->first();

        if (! $voiceSession instanceof VoiceSession) {
            return response()->json(['logged' => false], 404);
        }

        Log::warning('Voice session client error reported by browser.', [
            'session_id' => $voiceSession->getKey(),
            'room_name' => $voiceSession->room_name,
            'user_id' => $user->getKey(),
            'stage' => $validated['stage'],
            'error' => $validated['message'] ?? null,
        ]);

        return response()->json(['logged' => true]);
    }
}
