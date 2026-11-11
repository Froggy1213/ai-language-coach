<?php

namespace Tests\Feature\Billing;

use App\Models\StripeEvent;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Stripe webhook: signature, idempotency and the three events that matter.
 */
class StripeWebhookTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.secret' => 'sk_test_fake',
            'billing.webhook_secret' => self::SECRET,
            'billing.plans.entry.prices.month.stripe_price' => 'price_entry_month',
            'billing.plans.entry.prices.year.stripe_price' => 'price_entry_year',
            'billing.plans.standard.prices.month.stripe_price' => 'price_standard_month',
            'billing.plans.standard.prices.year.stripe_price' => 'price_standard_year',
            'billing.plans.pro.prices.month.stripe_price' => 'price_pro_month',
            'billing.plans.pro.prices.year.stripe_price' => 'price_pro_year',
        ]);
    }

    public function test_a_missing_webhook_secret_is_a_503_not_a_crash(): void
    {
        config(['billing.webhook_secret' => null]);

        $this->deliver($this->subscriptionEvent('evt_1', $this->subscriptionPayload(User::factory()->create())))
            ->assertStatus(503);
    }

    public function test_a_bad_signature_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->deliver(
            $this->subscriptionEvent('evt_1', $this->subscriptionPayload($user)),
            'a-different-secret-that-is-not-ours',
        );

        $response->assertStatus(400);
        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, StripeEvent::query()->count());
    }

    public function test_checkout_completed_links_the_stripe_customer_to_the_learner(): void
    {
        $user = User::factory()->create();

        $this->deliver([
            'id' => 'evt_checkout',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'object' => 'checkout.session',
                    'client_reference_id' => (string) $user->getKey(),
                    'customer' => 'cus_checkout',
                    'subscription' => 'sub_checkout',
                ],
            ],
        ])->assertOk()->assertJson(['received' => true, 'applied' => true]);

        $this->assertSame('cus_checkout', $user->refresh()->stripe_customer_id);
    }

    public function test_subscription_created_writes_the_plan_and_period(): void
    {
        $user = User::factory()->create();
        $start = now()->startOfMonth();
        $end = $start->copy()->addMonth();

        $this->deliver($this->subscriptionEvent('evt_created', $this->subscriptionPayload($user, $start, $end)))
            ->assertOk()
            ->assertJson(['applied' => true]);

        $subscription = Subscription::query()->sole();

        $this->assertSame($user->getKey(), $subscription->user_id);
        $this->assertSame('sub_test', $subscription->stripe_subscription_id);
        $this->assertSame('entry', $subscription->plan_code);
        $this->assertSame('month', $subscription->billing_interval);
        $this->assertSame('active', $subscription->status);
        $this->assertSame('price_entry_month', $subscription->stripe_price_id);
        $this->assertSame($start->toDateTimeString(), $subscription->usage_anchor_at?->toDateTimeString());
        $this->assertSame('cus_test', $user->refresh()->stripe_customer_id);
    }

    public function test_the_same_event_delivered_twice_is_a_no_op(): void
    {
        $user = User::factory()->create();
        $event = $this->subscriptionEvent('evt_same', $this->subscriptionPayload($user));

        $this->deliver($event)->assertOk()->assertJson(['applied' => true]);
        $this->deliver($event)->assertOk()->assertJson(['applied' => false]);

        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(1, StripeEvent::query()->count());
    }

    public function test_a_renewal_updates_the_period_and_the_cancellation_flag(): void
    {
        $user = User::factory()->create();
        $start = now()->startOfMonth();
        $end = $start->copy()->addMonth();

        $this->deliver($this->subscriptionEvent('evt_1', $this->subscriptionPayload($user, $start, $end)))
            ->assertOk();

        $renewalStart = $end;
        $renewalEnd = $end->copy()->addMonth();

        $payload = $this->subscriptionPayload($user, $renewalStart, $renewalEnd);
        $payload['cancel_at_period_end'] = true;

        $this->deliver($this->subscriptionEvent('evt_2', $payload))->assertOk();

        $subscription = Subscription::query()->sole();

        $this->assertSame($renewalStart->toDateTimeString(), $subscription->current_period_start?->toDateTimeString());
        $this->assertSame($renewalEnd->toDateTimeString(), $subscription->current_period_end?->toDateTimeString());
        $this->assertTrue($subscription->cancel_at_period_end);
    }

    public function test_a_deleted_subscription_stops_access_and_keeps_the_end_date(): void
    {
        $user = User::factory()->create();
        $start = now()->startOfMonth();
        $end = $start->copy()->addMonth();

        $this->deliver($this->subscriptionEvent('evt_1', $this->subscriptionPayload($user, $start, $end)))
            ->assertOk();

        $payload = $this->subscriptionPayload($user, $start, $end);
        $payload['status'] = 'canceled';

        $this->deliver([
            'id' => 'evt_deleted',
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => $payload],
        ])->assertOk();

        $subscription = Subscription::query()->sole();

        $this->assertSame('canceled', $subscription->status);
        $this->assertSame($end->toDateTimeString(), $subscription->ends_at?->toDateTimeString());
    }

    public function test_an_unknown_price_is_recorded_but_not_attributed_to_a_plan(): void
    {
        $user = User::factory()->create();
        $payload = $this->subscriptionPayload($user);
        $payload['items']['data'][0]['price']['id'] = 'price_retired';
        $payload['metadata']['plan_code'] = 'retired';

        $this->deliver($this->subscriptionEvent('evt_unknown', $payload))
            ->assertOk()
            ->assertJson(['applied' => true]);

        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(1, StripeEvent::query()->count());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function subscriptionEvent(string $id, array $payload): array
    {
        return [
            'id' => $id,
            'type' => 'customer.subscription.created',
            'data' => ['object' => $payload],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionPayload(
        User $user,
        ?\DateTimeInterface $start = null,
        ?\DateTimeInterface $end = null,
    ): array {
        $start ??= now()->startOfMonth();
        $end ??= Carbon::instance($start)->addMonth();

        return [
            'id' => 'sub_test',
            'object' => 'subscription',
            'customer' => 'cus_test',
            'status' => 'active',
            'current_period_start' => $start->getTimestamp(),
            'current_period_end' => $end->getTimestamp(),
            'cancel_at_period_end' => false,
            'metadata' => [
                'user_id' => (string) $user->getKey(),
                'plan_code' => 'entry',
                'interval' => 'month',
            ],
            'items' => [
                'data' => [
                    ['price' => ['id' => 'price_entry_month']],
                ],
            ],
        ];
    }

    /**
     * Deliver an event the way Stripe does: signature over
     * "{timestamp}.{raw body}", raw body passed through untouched.
     *
     * @param  array<string, mixed>  $event
     */
    private function deliver(array $event, string $secret = self::SECRET): TestResponse
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return $this->call(
            'POST',
            '/api/webhooks/stripe',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            content: $payload,
        );
    }
}
