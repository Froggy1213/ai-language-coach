<?php

namespace App\Billing;

use App\Enums\BillingInterval;
use App\Models\User;

/**
 * The Stripe calls billing needs, behind an interface.
 *
 * Everything that talks to Stripe goes through this seam so the domain tests
 * can assert what was sold without a network call, and so a Stripe outage is a
 * caught exception in one place instead of a scattered try/catch.
 */
interface StripeGateway
{
    /**
     * Create a Checkout session for a subscription and return its hosted URL.
     */
    public function checkoutUrl(
        User $user,
        Plan $plan,
        BillingInterval $interval,
        string $successUrl,
        string $cancelUrl,
    ): string;

    /**
     * Create a Billing Portal session and return its hosted URL.
     */
    public function portalUrl(User $user, string $returnUrl): string;

    /**
     * Cancel a subscription immediately.
     *
     * Called from account deletion: a deleted account must not keep being
     * charged, and stopping that is the one billing action that cannot be
     * deferred to a webhook.
     */
    public function cancelSubscription(string $stripeSubscriptionId): void;
}
