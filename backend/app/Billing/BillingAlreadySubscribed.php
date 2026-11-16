<?php

namespace App\Billing;

use GraphQL\Error\ClientAware;
use RuntimeException;

/**
 * A checkout was requested for a learner who already has a managed
 * subscription (`active`, `trialing`, or `past_due`).
 *
 * Starting a second Stripe Checkout session would create a duplicate
 * subscription alongside the existing one; plan changes and payment updates go
 * through the Stripe Billing Portal instead.
 */
final class BillingAlreadySubscribed extends RuntimeException implements ClientAware
{
    public static function forUser(): self
    {
        return new self('Learner already has an active subscription; use the billing portal to change plans.');
    }

    public function isClientSafe(): bool
    {
        return true;
    }
}
