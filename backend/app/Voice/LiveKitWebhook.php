<?php

namespace App\Voice;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

/**
 * Verifies that a request really came from our LiveKit server (plan §5).
 *
 * LiveKit signs its webhooks with the very same API key pair that signs access
 * tokens, so no second shared secret is needed — the `Authorization` header
 * carries a JWT signed with `LIVEKIT_API_SECRET`.
 *
 * The ready-made receiver the plan hoped for lives in the abandoned PHP SDK
 * (README, decision 1), so this is the ~30 lines it would have contributed.
 */
final class LiveKitWebhook
{
    /**
     * Rejects a request whose signature does not verify.
     *
     * @throws InvalidWebhookSignature
     */
    public function verify(string $authorizationHeader, string $rawBody): void
    {
        $secret = (string) config('voice.livekit.api_secret');
        $apiKey = (string) config('voice.livekit.api_key');

        if ($secret === '' || $apiKey === '') {
            throw new RuntimeException('LIVEKIT_API_KEY and LIVEKIT_API_SECRET must be set to verify webhooks.');
        }

        $token = $this->bearerToken($authorizationHeader);

        if ($token === null) {
            throw InvalidWebhookSignature::missing();
        }

        try {
            $claims = (array) JWT::decode($token, new Key($secret, 'HS256'));
        } catch (\Throwable $exception) {
            throw InvalidWebhookSignature::unverifiable($exception);
        }

        // The token must be one *we* issued: a token signed with the secret but
        // minted for a different LiveKit project would otherwise pass.
        if (($claims['iss'] ?? null) !== $apiKey) {
            throw InvalidWebhookSignature::wrongIssuer();
        }

        $this->verifyBodyHash($claims, $rawBody);
    }

    /**
     * The token commits to a digest of the exact bytes that were sent, so a
     * captured signature cannot be replayed with a doctored body.
     *
     * @param  array<string, mixed>  $claims
     */
    private function verifyBodyHash(array $claims, string $rawBody): void
    {
        $expected = $claims['sha256'] ?? null;

        if (! is_string($expected) || $expected === '') {
            throw InvalidWebhookSignature::missingBodyHash();
        }

        // hash_equals rather than JWT::constantTimeEquals: this compares two
        // strings in constant time, which is exactly the timing-attack defence
        // that method provides for arrays.
        if (! hash_equals(base64_encode(hash('sha256', $rawBody, true)), $expected)) {
            throw InvalidWebhookSignature::bodyMismatch();
        }
    }

    private function bearerToken(string $authorizationHeader): ?string
    {
        if (preg_match('/^Bearer\s+(.+)$/i', trim($authorizationHeader), $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }
}
