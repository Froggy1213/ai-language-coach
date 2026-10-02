<?php

namespace App\Voice;

use Firebase\JWT\JWT;
use RuntimeException;

/**
 * Mints the LiveKit access tokens this service hands out (plan §5 step 5).
 *
 * The package the plan originally named — `agence104/livekit-server-sdk` — is
 * uninstallable: every version pins `firebase/php-jwt` v6 (security advisory)
 * or guzzle ≤7 (README, decision 1). What that SDK actually does for us is
 * claim assembly and HMAC signing, which is what this class does.
 *
 * Two audiences use this class, and they need opposite grants:
 * - the browser, which may only join one room and speak in it (`participant()`);
 * - this backend, which calls the server API to create rooms and dispatch
 *   agents (`server()`).
 */
final class LiveKitToken
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
    ) {}

    /**
     * The learner's token: join exactly one room, publish and subscribe.
     *
     * `roomJoin` is a capability, not a default — a token without it is refused
     * — and pairing it with a single `room` grant is what keeps a leaked token
     * from opening somebody else's session.
     */
    public function participant(string $roomName, string $identity, ?string $name = null): string
    {
        return $this->encode([
            'roomJoin' => true,
            'room' => $roomName,
            'canPublish' => true,
            'canSubscribe' => true,
            'canPublishData' => true,
        ], $identity, $name);
    }

    /**
     * This backend's own token for the server API (RoomService, AgentDispatch
     * Service). Short-lived and never sent to a client.
     *
     * LiveKit's room-scoped admin endpoints (agent dispatch, participant listing)
     * check the grant against the room in the request, so a capability-only token
     * is refused; `roomCreate` is the exception, because the room does not exist
     * yet when it is called.
     */
    public function server(?string $roomName = null): string
    {
        $grants = [
            'roomCreate' => true,
            'roomList' => true,
            'roomAdmin' => true,
        ];

        if ($roomName !== null) {
            $grants['room'] = $roomName;
        }

        return $this->encode($grants, 'api');
    }

    /**
     * @param  array<string, mixed>  $grants
     */
    private function encode(array $grants, string $identity, ?string $name = null): string
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new RuntimeException('LIVEKIT_API_KEY and LIVEKIT_API_SECRET must be set to mint access tokens.');
        }

        // HS256 signs with a 256-bit key, and php-jwt refuses anything shorter
        // rather than minting a token that is weaker than it looks. Checked here
        // so a short secret from Secrets Manager is named as such instead of
        // surfacing as "Provided key is too short" from inside the signer.
        if (strlen($this->apiSecret) < 32) {
            throw new RuntimeException('LIVEKIT_API_SECRET must be at least 32 characters for HS256. Generate one with `openssl rand -base64 32`.');
        }

        $now = time();

        $claims = [
            'iss' => $this->apiKey,
            'sub' => $identity,
            'nbf' => $now,
            'iat' => $now,
            'exp' => $now + ((int) config('voice.livekit.token_ttl_minutes')) * 60,
            'video' => $grants,
            'metadata' => '',
        ];

        if ($name !== null) {
            // Shown in the LiveKit room roster; never carries PII — the room is
            // a primary key and identities are opaque by design.
            $claims['name'] = $name;
        }

        return JWT::encode($claims, $this->apiSecret, 'HS256');
    }
}
