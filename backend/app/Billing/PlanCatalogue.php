<?php

namespace App\Billing;

use App\Enums\BillingInterval;
use Illuminate\Contracts\Config\Repository;

/**
 * The paid plans, read from `config/billing.php`.
 *
 * One catalogue owns both directions of the mapping: a plan code becomes a
 * Stripe price when a checkout is created, and a Stripe price becomes a plan
 * code when a webhook arrives. Keeping the two in one class is what stops the
 * checkout and the webhook from disagreeing about what was bought.
 */
final class PlanCatalogue
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @return list<Plan>
     */
    public function all(): array
    {
        $plans = [];

        /** @var array<string, array{monthly_seconds: int, prices: array<string, array{amount: int, stripe_price: string|null}>}> $configured */
        $configured = (array) $this->config->get('billing.plans', []);

        foreach ($configured as $code => $definition) {
            $plans[] = new Plan(
                code: (string) $code,
                monthlySeconds: (int) $definition['monthly_seconds'],
                prices: $definition['prices'],
            );
        }

        return $plans;
    }

    public function find(string $code): ?Plan
    {
        foreach ($this->all() as $plan) {
            if ($plan->code === $code) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Resolve what a Stripe price id was sold as.
     *
     * @return array{0: Plan, 1: BillingInterval}|null
     */
    public function findByPriceId(string $priceId): ?array
    {
        if ($priceId === '') {
            return null;
        }

        foreach ($this->all() as $plan) {
            foreach (BillingInterval::cases() as $interval) {
                if ($plan->stripePriceId($interval) === $priceId) {
                    return [$plan, $interval];
                }
            }
        }

        return null;
    }
}
