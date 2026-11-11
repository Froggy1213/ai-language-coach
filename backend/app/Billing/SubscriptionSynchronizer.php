<?php

namespace App\Billing;

use App\Enums\BillingInterval;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Writes a Stripe subscription payload into `subscriptions`.
 *
 * Stripe is the source of truth, so the row is rewritten from the payload on
 * every event instead of being patched with deltas. Two details come from the
 * API shape rather than from taste:
 *
 *  - `current_period_start/end` live on the subscription in older API versions
 *    and on its first item in the 2025-03-31 "basil" version, so both places are
 *    read and the first one present wins.
 *  - the learner and the tier are copied onto the subscription's metadata at
 *    checkout, which is what makes the row creatable even when
 *    `subscription.created` arrives before `checkout.session.completed`.
 */
final class SubscriptionSynchronizer
{
    public function __construct(private readonly PlanCatalogue $plans) {}

    /**
     * Attach the Stripe customer to the learner who started the checkout.
     *
     * @param  array<string, mixed>  $session
     */
    public function checkoutCompleted(array $session): void
    {
        $userId = $this->userIdFrom($session);
        $customer = $session['customer'] ?? null;

        if ($userId === null || ! is_string($customer) || $customer === '') {
            return;
        }

        User::query()->whereKey($userId)->update(['stripe_customer_id' => $customer]);
    }

    /**
     * Create or refresh the row for one Stripe subscription.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sync(array $payload): void
    {
        $stripeId = $payload['id'] ?? null;

        if (! is_string($stripeId) || $stripeId === '') {
            Log::warning('Stripe subscription webhook without an id.', ['type' => $payload['object'] ?? null]);

            return;
        }

        $subscription = Subscription::query()
            ->where('stripe_subscription_id', $stripeId)
            ->first();

        $userId = $this->userIdFrom($payload);

        if (! $subscription instanceof Subscription) {
            if ($userId === null) {
                // Without a learner the row cannot be attributed. Dropping it is
                // better than inventing one; a later event with metadata, or the
                // operator, can still reconcile it.
                Log::warning('Stripe subscription webhook without a learner.', ['subscription' => $stripeId]);

                return;
            }

            $subscription = new Subscription([
                'user_id' => $userId,
                'stripe_subscription_id' => $stripeId,
            ]);
        }

        $priceId = (string) data_get($payload, 'items.data.0.price.id', '');
        $match = $this->plans->findByPriceId($priceId);

        $planCode = $match[0]->code ?? (string) data_get($payload, 'metadata.plan_code', '');
        $interval = $match[1] ?? BillingInterval::tryFrom((string) data_get($payload, 'metadata.interval', ''));
        $plan = $this->plans->find($planCode);

        if ($plan === null || $interval === null) {
            Log::warning('Stripe subscription for an unknown plan.', [
                'subscription' => $stripeId,
                'price' => $priceId,
                'plan_code' => $planCode,
            ]);

            return;
        }

        $periodStart = $this->timestamp($payload, 'current_period_start')
            ?? $this->timestamp($payload, 'items.data.0.current_period_start');
        $periodEnd = $this->timestamp($payload, 'current_period_end')
            ?? $this->timestamp($payload, 'items.data.0.current_period_end');

        $subscription->stripe_price_id = $priceId !== '' ? $priceId : $subscription->stripe_price_id;
        $subscription->plan_code = $plan->code;
        $subscription->billing_interval = $interval->value;
        $subscription->status = (string) ($payload['status'] ?? $subscription->status ?? 'incomplete');
        $subscription->cancel_at_period_end = (bool) ($payload['cancel_at_period_end'] ?? false);

        if ($periodStart !== null) {
            $subscription->current_period_start = $periodStart;
        }

        if ($periodEnd !== null) {
            $subscription->current_period_end = $periodEnd;
        }

        // The monthly allowance window is anchored once and then never moved:
        // rewriting it on every renewal would give the learner a fresh window
        // for each webhook.
        $subscription->usage_anchor_at ??= $periodStart ?? CarbonImmutable::now();

        $subscription->save();

        $this->linkCustomer(
            userId: (int) $subscription->user_id,
            customer: (string) ($payload['customer'] ?? ''),
        );
    }

    /**
     * Stripe ended the subscription. Access stops now; the row keeps its last
     * period so the billing screen can still explain what was paid for.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deleted(array $payload): void
    {
        $this->sync($payload);

        $subscription = Subscription::query()
            ->where('stripe_subscription_id', (string) ($payload['id'] ?? ''))
            ->first();

        if (! $subscription instanceof Subscription) {
            return;
        }

        $subscription->status = 'canceled';
        $subscription->ends_at = $subscription->current_period_end ?? CarbonImmutable::now();
        $subscription->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function userIdFrom(array $payload): ?int
    {
        $candidate = $payload['client_reference_id'] ?? data_get($payload, 'metadata.user_id');

        return is_numeric($candidate) ? (int) $candidate : null;
    }

    private function linkCustomer(int $userId, string $customer): void
    {
        if ($customer === '') {
            return;
        }

        User::query()
            ->whereKey($userId)
            ->whereNull('stripe_customer_id')
            ->update(['stripe_customer_id' => $customer]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function timestamp(array $payload, string $key): ?CarbonImmutable
    {
        $value = data_get($payload, $key);

        return is_numeric($value)
            ? CarbonImmutable::createFromTimestampUTC((int) $value)
            : null;
    }
}
