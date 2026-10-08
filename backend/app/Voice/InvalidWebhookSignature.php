<?php

namespace App\Voice;

use RuntimeException;

/**
 * A LiveKit webhook did not come from our LiveKit server, or did not come
 * intact (plan §5).
 *
 * Every variant is a 401: whether the header was absent, the signature did not
 * verify, or the body was altered after signing is of no interest to the caller
 * — in each case the request is refused.
 *
 * Each variant also carries a stable `reason()` code. The caller logs that code
 * rather than the message: the message is prose meant for a human and may be
 * reworded, while the code is what a log query or an alert rule keys on — and it
 * keeps attacker-influenced text out of the log context.
 */
final class InvalidWebhookSignature extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * One of: missing, unverifiable, wrongIssuer, missingBodyHash, bodyMismatch.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    public static function missing(): self
    {
        return new self('The request carries no LiveKit signature.', 'missing');
    }

    public static function unverifiable(\Throwable $previous): self
    {
        return new self('The LiveKit signature could not be verified.', 'unverifiable', $previous);
    }

    public static function wrongIssuer(): self
    {
        return new self('The LiveKit signature was issued for a different project.', 'wrongIssuer');
    }

    public static function missingBodyHash(): self
    {
        return new self('The LiveKit signature does not commit to a body digest.', 'missingBodyHash');
    }

    public static function bodyMismatch(): self
    {
        return new self('The LiveKit signature does not match the request body.', 'bodyMismatch');
    }
}
