<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        $start = now()->startOfMonth();

        return [
            'user_id' => User::factory(),
            'stripe_subscription_id' => 'sub_'.fake()->unique()->bothify('????????????????'),
            'stripe_price_id' => 'price_test_entry_month',
            'plan_code' => 'entry',
            'billing_interval' => 'month',
            'status' => 'active',
            'current_period_start' => $start,
            'current_period_end' => $start->copy()->addMonth(),
            'cancel_at_period_end' => false,
            'usage_anchor_at' => $start,
            'ends_at' => null,
        ];
    }

    /**
     * A subscription for a named tier and interval.
     */
    public function forPlan(string $planCode, string $interval = 'month'): static
    {
        return $this->state(fn (): array => [
            'plan_code' => $planCode,
            'billing_interval' => $interval,
            'stripe_price_id' => "price_test_{$planCode}_{$interval}",
        ]);
    }

    /**
     * A subscription Stripe has already ended.
     */
    public function canceled(): static
    {
        return $this->state(fn (): array => [
            'status' => 'canceled',
            'ends_at' => now()->subDay(),
        ]);
    }

    /**
     * A subscription whose latest renewal failed.
     */
    public function pastDue(): static
    {
        return $this->state(fn (): array => [
            'status' => 'past_due',
        ]);
    }
}
