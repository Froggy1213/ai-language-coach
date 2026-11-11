<?php

namespace Tests\Support;

use App\Billing\Plan;
use App\Billing\StripeGateway;
use App\Enums\BillingInterval;
use App\Models\User;

/**
 * A StripeGateway that records what billing asked for and returns fixed URLs.
 *
 * Billing tests are about the plan, the redirect and the error contract; they
 * must not depend on Stripe being reachable or on an API key being present.
 */
final class FakeStripeGateway implements StripeGateway
{
    /** @var list<array{user: User, plan: Plan, interval: BillingInterval, successUrl: string, cancelUrl: string}> */
    public array $checkouts = [];

    /** @var list<array{user: User, returnUrl: string}> */
    public array $portals = [];

    public string $checkoutUrl = 'https://checkout.stripe.test/cs_test';

    public string $portalUrl = 'https://billing.stripe.test/ps_test';

    /** @var list<string> */
    public array $cancellations = [];

    public ?\Throwable $cancelFailure = null;

    public function checkoutUrl(
        User $user,
        Plan $plan,
        BillingInterval $interval,
        string $successUrl,
        string $cancelUrl,
    ): string {
        $this->checkouts[] = compact('user', 'plan', 'interval', 'successUrl', 'cancelUrl');

        return $this->checkoutUrl;
    }

    public function portalUrl(User $user, string $returnUrl): string
    {
        $this->portals[] = compact('user', 'returnUrl');

        return $this->portalUrl;
    }

    public function cancelSubscription(string $stripeSubscriptionId): void
    {
        if ($this->cancelFailure !== null) {
            throw $this->cancelFailure;
        }

        $this->cancellations[] = $stripeSubscriptionId;
    }
}
