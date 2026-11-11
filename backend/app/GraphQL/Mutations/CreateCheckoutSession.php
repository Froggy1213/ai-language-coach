<?php

namespace App\GraphQL\Mutations;

use App\Billing\BillingCheckout;
use App\Billing\BillingNotConfigured;
use App\Billing\BillingPlanNotFound;
use App\Enums\BillingInterval;
use App\Models\User;
use GraphQL\Error\Error;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Stripe\Exception\ApiErrorException;

/**
 * Creates the Stripe Checkout session for a chosen plan and returns its URL.
 *
 * The mutation never sees a card: the browser navigates to Stripe, pays there,
 * and comes back through `/settings?checkout=success`. What it does own is the
 * mapping from the paywall's typed failure to an error code the client can
 * branch on.
 */
final class CreateCheckoutSession
{
    public function __construct(private readonly BillingCheckout $checkout) {}

    /**
     * @param  array{planCode: string, interval: BillingInterval|string}  $args
     * @return array{url: string}
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $user = $context->user();
        assert($user instanceof User);

        $interval = $args['interval'] instanceof BillingInterval
            ? $args['interval']
            : BillingInterval::from((string) $args['interval']);

        try {
            return ['url' => $this->checkout->checkoutUrl($user, $args['planCode'], $interval)];
        } catch (BillingPlanNotFound $exception) {
            throw new Error($exception->getMessage(), extensions: ['code' => 'BILLING_PLAN_NOT_FOUND'], previous: $exception);
        } catch (BillingNotConfigured $exception) {
            throw new Error($exception->getMessage(), extensions: ['code' => 'BILLING_NOT_CONFIGURED'], previous: $exception);
        } catch (ApiErrorException $exception) {
            report($exception);

            throw new Error(
                'Stripe could not start a checkout session.',
                extensions: ['code' => 'BILLING_PROVIDER_ERROR'],
                previous: $exception,
            );
        }
    }
}
