<?php

namespace App\Enums;

/**
 * Reasons recorded in `voice_sessions.fail_reason` when a session terminates abnormally.
 */
enum VoiceSessionFailReason: string
{
    // Agent-reported failures (reported by the Python voice agent via POST /internal/sessions/{id}/fail).
    case SttFailed = 'stt_failed';
    case TtsFailed = 'tts_failed';
    case LlmFailed = 'llm_failed';
    case AgentError = 'agent_error';

    // Server-side / LiveKit infrastructure failures.
    case LivekitServerShutdown = 'livekit_server_shutdown';
    case LivekitRoomOpenFailed = 'livekit_room_open_failed';

    // Session start orchestration failures.
    case VoiceStartStale = 'voice_start_stale';
    case VoiceFleetBusy = 'voice_fleet_busy';
    case VoiceStartFailed = 'voice_start_failed';

    /**
     * Failures the voice agent is permitted to report over HTTP (plan §5).
     *
     * @return list<self>
     */
    public static function agentReported(): array
    {
        return [
            self::SttFailed,
            self::TtsFailed,
            self::LlmFailed,
            self::AgentError,
        ];
    }
}
