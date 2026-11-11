<?php

namespace App\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies one Stripe event exactly once.
 *
 * The dedupe row and the effect share a transaction: if the effect throws, the
 * dedupe row is rolled back with it and Stripe's retry is allowed to try again.
 * A duplicate delivery finds the row already there and does nothing.
 */
final class StripeWebhookHandler
{
    public function __construct(private readonly SubscriptionSynchronizer $subscriptions) {}

    /**
     * @param  array<string, mixed>  $event
     * @return bool Whether this delivery changed anything (false = duplicate).
     */
    public function handle(array $event): bool
    {
        $eventId = $event['id'] ?? null;
        $type = $event['type'] ?? null;

        if (! is_string($eventId) || ! is_string($type)) {
            Log::warning('Stripe webhook without an id or type.');

            return false;
        }

        return DB::transaction(function () use ($event, $eventId, $type): bool {
            $inserted = DB::table('stripe_events')->insertOrIgnore([
                'stripe_event_id' => $eventId,
                'type' => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return false;
            }

            /** @var array<string, mixed> $object */
            $object = (array) data_get($event, 'data.object', []);

            match ($type) {
                'checkout.session.completed' => $this->subscriptions->checkoutCompleted($object),
                'customer.subscription.created',
                'customer.subscription.updated' => $this->subscriptions->sync($object),
                'customer.subscription.deleted' => $this->subscriptions->deleted($object),
                // Everything else (invoices, payment intents, customer updates)
                // is acknowledged and recorded, not acted on: the subscription
                // events above already carry the state the learner sees.
                default => null,
            };

            return true;
        });
    }
}
