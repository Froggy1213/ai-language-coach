<?php

namespace App\Billing;

use App\Enums\BillingInterval;
use App\Models\User;

/**
 * Builds the two Stripe flows the interface can start: a subscription checkout
 * and the billing portal.
 *
 * Redirect URLs are assembled here, not in the GraphQL mutation, so both the
 * success and cancel destinations are a property of billing rather than of the
 * resolver that happened to call it.
 */
final class BillingCheckout
{
    public function __construct(
        private readonly StripeGateway $gateway,
        private readonly PlanCatalogue $plans,
    ) {}

    public function checkoutUrl(User $user, string $planCode, BillingInterval $interval): string
    {
        $plan = $this->plans->find($planCode)
            ?? throw BillingPlanNotFound::forCode($planCode);

        if (! $plan->supports($interval) || $plan->stripePriceId($interval) === null) {
            throw BillingNotConfigured::missingPrice($plan->code, $interval);
        }

        $frontend = (string) config('billing.frontend_url');

        return $this->gateway->checkoutUrl(
            user: $user,
            plan: $plan,
            interval: $interval,
            successUrl: $frontend.'/settings?checkout=success',
            cancelUrl: $frontend.'/pricing?checkout=cancelled',
        );
    }

    public function portalUrl(User $user): string
    {
        return $this->gateway->portalUrl($user, (string) config('billing.frontend_url').'/settings');
    }
}
