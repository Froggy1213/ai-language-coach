<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LiveKit Server
    |--------------------------------------------------------------------------
    |
    | Self-hosted on EC2 with an Elastic IP (plan §8), never LiveKit Cloud, so
    | the URL is the WebSocket endpoint the browser dials directly — it does not
    | go through the ALB. `url` is the address this service dials and
    | `public_url` the one the browser dials; they differ only when the backend
    | runs somewhere that cannot share a hostname with the browser.
    |
    | `api_key` / `api_secret` sign every access token *and* every webhook, which
    | is why the two directions need no separate shared secret: LiveKit sends the
    | same key pair back in the webhook's `Authorization` header.
    |
    */

    'livekit' => [
        /*
        | The address *this backend* dials for the Twirp server API, so a
        | containerised deployment names the service (`ws://livekit:7880`).
        */
        'url' => env('LIVEKIT_URL', 'ws://localhost:7880'),

        /*
        | The address the *browser* dials. Same server, and on a single-host
        | setup the same string — which is why this is nullable and
        | `VoiceSessionConnection@url` falls back to `url`.
        |
        | The two stop being the same as soon as the API runs in a container:
        | a learner's browser cannot resolve `livekit`, so a deployment that
        | points LIVEKIT_URL at a service name must also set LIVEKIT_PUBLIC_URL
        | to an address the browser can reach, or every session hands the
        | learner a hostname that does not exist.
        */
        'public_url' => env('LIVEKIT_PUBLIC_URL'),

        'api_key' => env('LIVEKIT_API_KEY'),
        'api_secret' => env('LIVEKIT_API_SECRET'),

        /*
        | Plan §5 asks for a short TTL rather than the SDK default of six hours:
        | the token only has to outlive the handshake, and a leaked one should
        | not stay usable for an afternoon.
        */
        'token_ttl_minutes' => (int) env('LIVEKIT_TOKEN_TTL_MINUTES', 15),

        /*
        | Every server API call is a short Twirp POST; a hung LiveKit node must
        | not hold a GraphQL request open.
        */
        'api_timeout_seconds' => (int) env('LIVEKIT_API_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Room Policy
    |--------------------------------------------------------------------------
    |
    | A room is created explicitly before the agent is dispatched, so these
    | limits are the server's rather than defaults.
    |
    */

    'room' => [
        /*
        | How long an empty room survives without the agent. Also the backstop
        | for a learner who closes the tab: room_finished then arrives with
        | ROOM_END_IDLE_TIMEOUT, which is what marks the session `abandoned`.
        */
        'empty_timeout_seconds' => (int) env('VOICE_ROOM_EMPTY_TIMEOUT', 300),

        /*
        | The learner plus the agent. Nobody else can join — the room name is
        | derived from a primary key, so it is guessable by design and the cap
        | is what makes guessing harmless. The access token is the real check.
        */
        'max_participants' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Dispatch
    |--------------------------------------------------------------------------
    |
    | Explicit dispatch with job metadata (plan §2), not automatic dispatch: the
    | worker is told which session, grammar point and practice prompt the job is
    | about, so it never has to ask the API what it is supposed to do.
    |
    */

    'agent' => [
        'name' => env('VOICE_AGENT_NAME', 'ai-language-coach'),

        /*
        | How long requestVoiceToken waits for the dispatched agent to join the
        | room before giving up with VOICE_FLEET_BUSY (plan §5 step 4). The
        | learner is holding a spinner for exactly this long.
        */
        'join_timeout_seconds' => (float) env('VOICE_AGENT_JOIN_TIMEOUT', 5),

        /*
        | Poll interval while waiting for that join. LiveKit has no
        | "wait for participant" call, so this is the resolution of the check.
        */
        'poll_interval_ms' => (int) env('VOICE_AGENT_POLL_INTERVAL_MS', 250),
    ],

    /*
    |--------------------------------------------------------------------------
    | Internal Agent Endpoints
    |--------------------------------------------------------------------------
    |
    | The Python worker reports its own failures here before disconnecting
    | (plan §5 — `POST /internal/sessions/{id}/fail`). LiveKit never sees these:
    | a crashed STT stream is not a room event.
    |
    */

    'internal_secret' => env('VOICE_INTERNAL_SECRET'),

];
