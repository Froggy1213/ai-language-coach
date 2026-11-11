<?php

namespace App\GraphQL\Mutations;

use App\Billing\BillingCheckout;
use App\Billing\BillingNotConfigured;
use App\Models\User;
use GraphQL\Error\Error;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Stripe\Exception\ApiErrorException;

/**
 * Opens the Stripe billing portal so the learner can change a card, switch a
 * plan or cancel.
 *
 * Cancellation is handled by Stripe (`cancel_at_period_end`), and the webhook
 * brings the new state back; there is no local cancel mutation to drift from it.
 */
final class CreateBillingPortalSession
{
    public function __construct(private readonly BillingCheckout $checkout) {}

    /**
     * @return array{url: string}
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $user = $context->user();
        assert($user instanceof User);

        try {
            return ['url' => $this->checkout->portalUrl($user)];
        } catch (BillingNotConfigured $exception) {
            throw new Error($exception->getMessage(), extensions: ['code' => 'BILLING_NOT_CONFIGURED'], previous: $exception);
        } catch (ApiErrorException $exception) {
            report($exception);

            throw new Error(
                'Stripe could not open the billing portal.',
                extensions: ['code' => 'BILLING_PROVIDER_ERROR'],
                previous: $exception,
            );
        }
    }
}
