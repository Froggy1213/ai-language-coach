<?php

namespace App\Billing;

use App\Enums\BillingInterval;

/**
 * A paid tier as the backend understands it: a code, a monthly allowance, and
 * one price per billing interval.
 *
 * The plan is money and minutes only. Its Japanese name and description live in
 * the frontend's string catalogue, keyed by {@see $code}, so the API never has
 * to speak the interface language.
 */
final readonly class Plan
{
    /**
     * @param  array<string, array{amount: int, stripe_price: string|null}>  $prices
     */
    public function __construct(
        public string $code,
        public int $monthlySeconds,
        public array $prices,
    ) {}

    public function includedMinutes(): int
    {
        return intdiv($this->monthlySeconds, 60);
    }

    public function supports(BillingInterval $interval): bool
    {
        return isset($this->prices[$interval->value]);
    }

    public function amountFor(BillingInterval $interval): int
    {
        return (int) ($this->prices[$interval->value]['amount'] ?? 0);
    }

    public function stripePriceId(BillingInterval $interval): ?string
    {
        $price = $this->prices[$interval->value]['stripe_price'] ?? null;

        return is_string($price) && $price !== '' ? $price : null;
    }
}
