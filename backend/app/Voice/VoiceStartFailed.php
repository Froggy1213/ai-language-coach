<?php

namespace App\Voice;

use GraphQL\Error\ClientAware;
use RuntimeException;
use Throwable;

/**
 * The voice session failed to start on the server (plan §5).
 *
 * Any failure between room creation and the agent joining that is not a
 * fleet-busy timeout: LiveKit refusing a call, room creation failing, or
 * network errors.
 *
 * Client-aware because the message is safe to show to the learner, avoiding
 * raw internal server error messages or stack traces.
 */
final class VoiceStartFailed extends RuntimeException implements ClientAware
{
    public function __construct(
        string $message = 'Could not start voice session. Please try again later.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isClientSafe(): bool
    {
        return true;
    }

    public static function forFailure(Throwable $previous): self
    {
        return new self(previous: $previous);
    }
}
