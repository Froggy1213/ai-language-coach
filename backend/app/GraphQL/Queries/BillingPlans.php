<?php

namespace App\GraphQL\Queries;

use App\Billing\Plan;
use App\Billing\PlanCatalogue;
use App\Enums\BillingInterval;

/**
 * The paid plans, for the public pricing screen.
 *
 * Returns codes and numbers only: the Japanese plan names and descriptions are
 * interface copy and live in `frontend/app/constants/strings.ts`, keyed by code.
 */
final class BillingPlans
{
    public function __construct(private readonly PlanCatalogue $plans) {}

    /**
     * @return list<array{code: string, monthlyPriceJpy: int, yearlyPriceJpy: int, includedMinutes: int}>
     */
    public function __invoke(): array
    {
        return array_map(
            fn (Plan $plan): array => [
                'code' => $plan->code,
                'monthlyPriceJpy' => $plan->amountFor(BillingInterval::Month),
                'yearlyPriceJpy' => $plan->amountFor(BillingInterval::Year),
                'includedMinutes' => $plan->includedMinutes(),
            ],
            $this->plans->all(),
        );
    }
}
