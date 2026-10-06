<?php

namespace App\GraphQL\Resolvers;

use App\Models\VoiceSession;
use App\Voice\LiveKitToken;

/**
 * Presents the connection details for a voice session (plan §4).
 *
 * The token is minted when the field is read rather than stored on the row:
 * it is short-lived by design (plan §5 — 10–15 minutes, not the SDK default),
 * so a token saved at session creation would be expired by the time a learner
 * came back to an idle session. A field resolver keeps that freshness in one
 * place instead of spreading it across the places that return a session.
 */
final class VoiceSessionConnection
{
    public function __construct(private readonly LiveKitToken $tokens) {}

    /**
     * @param  array<string, mixed>  $args
     */
    public function token(VoiceSession $session, array $args): ?string
    {
        if ($session->status->isTerminal()) {
            return null;
        }

        return $this->tokens->participant(
            roomName: $session->room_name,
            // Opaque on purpose: LiveKit records identities in its own logs and
            // does not redact them (plan §5 — no PII in identity or room name).
            identity: 'learner-'.$session->user_id,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function url(VoiceSession $session, array $args): string
    {
        // Reading the config here rather than trusting the client's build-time
        // environment keeps one source of truth for where voice runs.
        //
        // `public_url` exists because that single source has two audiences: the
        // address this backend dials for the Twirp API, and the address the
        // browser dials for WebRTC. They coincide on a single host, so the
        // fallback is what keeps the ordinary local setup a one-key config.
        return (string) (config('voice.livekit.public_url') ?? config('voice.livekit.url'));
    }
}
