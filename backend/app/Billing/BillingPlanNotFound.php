<?php

namespace App\Billing;

use GraphQL\Error\ClientAware;
use RuntimeException;

/**
 * A checkout was asked for a plan code that is not in `config/billing.php`.
 *
 * This is a client bug or a stale pricing screen, not an operational failure,
 * so the learner-facing answer is simply "this plan is not available".
 */
final class BillingPlanNotFound extends RuntimeException implements ClientAware
{
    public static function forCode(string $code): self
    {
        return new self("Unknown billing plan [{$code}].");
    }

    public function isClientSafe(): bool
    {
        return true;
    }
}
