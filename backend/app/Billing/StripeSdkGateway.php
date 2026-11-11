<?php

namespace App\Billing;

use App\Enums\BillingInterval;
use App\Models\User;
use Stripe\StripeClient;

/**
 * Stripe through the official SDK.
 *
 * The learner is the Stripe customer: `users.stripe_customer_id` is written on
 * first use with an idempotency key derived from the user id, so a double-click
 * on "subscribe" cannot create two customers for one account.
 */
final class StripeSdkGateway implements StripeGateway
{
    public function __construct(private readonly string $secret) {}

    public function checkoutUrl(
        User $user,
        Plan $plan,
        BillingInterval $interval,
        string $successUrl,
        string $cancelUrl,
    ): string {
        $priceId = $plan->stripePriceId($interval)
            ?? throw BillingNotConfigured::missingPrice($plan->code, $interval);

        $metadata = [
            'user_id' => (string) $user->getKey(),
            'plan_code' => $plan->code,
            'interval' => $interval->value,
        ];

        $session = $this->client()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $this->customerId($user),
            'line_items' => [
                ['price' => $priceId, 'quantity' => 1],
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $user->getKey(),
            // Copied onto the subscription itself, so a `subscription.created`
            // webhook that arrives before `checkout.session.completed` still
            // knows which learner and which tier it belongs to.
            'subscription_data' => ['metadata' => $metadata],
            'metadata' => $metadata,
        ], [
            'idempotency_key' => "kirie-checkout-{$user->getKey()}-{$plan->code}-{$interval->value}",
        ]);

        return (string) $session->url;
    }

    public function portalUrl(User $user, string $returnUrl): string
    {
        $session = $this->client()->billingPortal->sessions->create([
            'customer' => $this->customerId($user),
            'return_url' => $returnUrl,
        ]);

        return (string) $session->url;
    }

    public function cancelSubscription(string $stripeSubscriptionId): void
    {
        // Idempotent: a second delete after a retry returns the same cancelled
        // subscription instead of creating a second cancellation.
        $this->client()->subscriptions->cancel($stripeSubscriptionId, [], [
            'idempotency_key' => "kirie-cancel-{$stripeSubscriptionId}",
        ]);
    }

    /**
     * The Stripe customer id for this learner, created on first use.
     */
    private function customerId(User $user): string
    {
        if (is_string($user->stripe_customer_id) && $user->stripe_customer_id !== '') {
            return $user->stripe_customer_id;
        }

        $customer = $this->client()->customers->create([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['user_id' => (string) $user->getKey()],
        ], [
            'idempotency_key' => "kirie-customer-{$user->getKey()}",
        ]);

        $user->forceFill(['stripe_customer_id' => $customer->id])->save();

        return (string) $customer->id;
    }

    private function client(): StripeClient
    {
        if ($this->secret === '') {
            throw BillingNotConfigured::missingSecret();
        }

        return new StripeClient($this->secret);
    }
}
