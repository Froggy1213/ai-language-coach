<?php

namespace App\Enums;

/**
 * How often Stripe charges a subscription.
 *
 * The backed value is what the GraphQL enum exposes on the wire and what the
 * `subscriptions.interval` column stores, so a payload round-trips without a
 * mapping layer.
 */
enum BillingInterval: string
{
    case Month = 'month';
    case Year = 'year';
}
