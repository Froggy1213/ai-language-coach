<?php

namespace App\Voice;

use GraphQL\Error\ClientAware;
use RuntimeException;

/**
 * No voice-agent worker took the job in time (plan §5 step 4).
 *
 * The room was created and the dispatch was accepted, but no agent process
 * joined before the wait expired — the fleet is saturated. The learner gets a
 * retryable answer instead of a spinner that never resolves.
 *
 * Client-aware because the message is written for the learner, not for a log:
 * graphql-php only forwards an exception's message to the client when the
 * exception says it is safe to do so.
 */
final class VoiceFleetBusy extends RuntimeException implements ClientAware
{
    public function isClientSafe(): bool
    {
        return true;
    }

    public static function forWaiting(float $seconds): self
    {
        return new self("No voice agent joined the room within {$seconds} seconds.");
    }

    public static function forDispatchFailure(int $status): self
    {
        return new self("LiveKit refused the agent dispatch with HTTP {$status}.");
    }
}
