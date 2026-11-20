<?php

namespace App\Billing;

use GraphQL\Error\ClientAware;
use RuntimeException;

/**
 * The Stripe billing portal was requested for a learner who has no Stripe
 * customer or subscription history to manage.
 */
final class BillingPortalUnavailable extends RuntimeException implements ClientAware
{
    public static function noCustomer(): self
    {
        return new self('Learner has no billing profile or subscription to manage.');
    }

    public function isClientSafe(): bool
    {
        return true;
    }
}
