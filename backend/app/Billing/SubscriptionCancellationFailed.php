<?php

namespace App\Billing;

use GraphQL\Error\ClientAware;
use RuntimeException;

/**
 * Stripe refused or could not be reached while cancelling a subscription during
 * account deletion.
 *
 * Deletion fails rather than proceeds: the local row is the only pointer to the
 * Stripe subscription, so deleting it while the subscription still charges the
 * learner would make the mistake unrecoverable.
 */
final class SubscriptionCancellationFailed extends RuntimeException implements ClientAware
{
    public static function forSubscription(string $stripeSubscriptionId): self
    {
        return new self("Could not cancel Stripe subscription {$stripeSubscriptionId}. Please try again.");
    }

    public function isClientSafe(): bool
    {
        return true;
    }
}
