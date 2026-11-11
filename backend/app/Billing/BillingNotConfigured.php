<?php

namespace App\Billing;

use App\Enums\BillingInterval;
use GraphQL\Error\ClientAware;
use RuntimeException;

/**
 * Billing was asked to do something the deployment cannot do yet: no Stripe
 * secret, no price id for the chosen interval, or no webhook secret.
 *
 * Client-aware so the GraphQL seam can answer a typed error instead of a 500;
 * the message names the missing setting, because the person reading it is the
 * operator, not the learner.
 */
final class BillingNotConfigured extends RuntimeException implements ClientAware
{
    public static function missingSecret(): self
    {
        return new self('Stripe is not configured: set STRIPE_SECRET.');
    }

    public static function missingWebhookSecret(): self
    {
        return new self('Stripe is not configured: set STRIPE_WEBHOOK_SECRET.');
    }

    public static function missingPrice(string $planCode, BillingInterval $interval): self
    {
        $variable = 'STRIPE_PRICE_'.mb_strtoupper($planCode).'_'.mb_strtoupper($interval->value);

        return new self("Plan {$planCode} has no Stripe price for {$interval->value}: set {$variable}.");
    }

    public function isClientSafe(): bool
    {
        return true;
    }
}
