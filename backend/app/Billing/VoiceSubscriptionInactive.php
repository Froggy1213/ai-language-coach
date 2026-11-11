<?php

namespace App\Billing;

use GraphQL\Error\ClientAware;
use RuntimeException;

/**
 * The learner has a subscription, but it is not in a state that allows a new
 * session — `past_due`, `unpaid`, `incomplete`, or `canceled`.
 *
 * The client answers this by sending the learner to the billing portal, which
 * is why the status travels with the exception.
 */
final class VoiceSubscriptionInactive extends RuntimeException implements ClientAware
{
    private function __construct(public readonly string $status, string $message)
    {
        parent::__construct($message);
    }

    public static function forStatus(string $status): self
    {
        return new self(
            status: $status,
            message: "The subscription is not active (status: {$status}). Update the payment method to continue.",
        );
    }

    public function isClientSafe(): bool
    {
        return true;
    }
}
