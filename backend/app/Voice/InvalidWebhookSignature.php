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
 */
final class InvalidWebhookSignature extends RuntimeException
{
    public static function missing(): self
    {
        return new self('The request carries no LiveKit signature.');
    }

    public static function unverifiable(\Throwable $previous): self
    {
        return new self('The LiveKit signature could not be verified.', previous: $previous);
    }

    public static function wrongIssuer(): self
    {
        return new self('The LiveKit signature was issued for a different project.');
    }

    public static function missingBodyHash(): self
    {
        return new self('The LiveKit signature does not commit to a body digest.');
    }

    public static function bodyMismatch(): self
    {
        return new self('The LiveKit signature does not match the request body.');
    }
}
